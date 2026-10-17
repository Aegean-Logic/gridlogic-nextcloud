<?php

declare(strict_types=1);

namespace OCA\GridCraft\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The co-editing sequencer: one session per file, an op log with gap-free positions, and who's
 * in it. It never looks inside ops (GridLogic in each browser does all the merging); it only
 * orders them, keeps them until the file is saved past them, and says where to start from.
 */
class CollabService {
	/** A member not heard from for this long no longer counts as in the session. */
	public const ACTIVE_SECONDS = 60;
	/** The largest op accepted (JSON text). */
	public const MAX_OP_BYTES = 16 * 1024 * 1024;
	/** The most log entries per sync. */
	public const MAX_BATCH = 500;
	/** The largest position (selection) kept per member. */
	public const MAX_SELECTION_BYTES = 8192;
	/** Log entries kept before the last checkpoint, for members still catching up. */
	public const KEEP_BEFORE_CHECKPOINT = 1000;
	/** Client ids stay below 2^32: GridLogic puts them in the high half of new sheet ids. */
	private const MAX_CLIENT = 4294967295;

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Joins the session for a file, starting one if there's none. `$etag` is the file's ETag as
	 * the joiner loaded it. Returns `['retry' => true]` if the file was saved for the session
	 * after that (load it again), otherwise the session, the joiner's client id, the log
	 * position the loaded file is at, and the log entries after it.
	 */
	public function join(int $fileId, string $etag, string $userId, string $displayName): array {
		$etag = self::bareEtag($etag);
		for ($attempt = 0; ; $attempt++) {
			$this->db->beginTransaction();
			try {
				$result = $this->joinLocked($fileId, $etag, $userId, $displayName);
				if (isset($result['retry'])) {
					$this->db->rollBack();
				} else {
					$this->db->commit();
				}
				return $result;
			} catch (DbException $e) {
				$this->db->rollBack();
				// Two people starting the session at once: the second joins the first's.
				if ($attempt === 0 && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					continue;
				}
				throw $e;
			}
		}
	}

	private function joinLocked(int $fileId, string $etag, string $userId, string $displayName): array {
		$now = $this->time->getTime();
		// Taking a client id locks the session row for the rest of the transaction.
		$qb = $this->db->getQueryBuilder();
		$taken = $qb->update('gridcraft_sessions')
			->set('next_client', $qb->createFunction('next_client + 1'))
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		if ($taken === 0) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('gridcraft_sessions')->values([
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'last_seq' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'checkpoint_seq' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'checkpoint_etag' => $qb->createNamedParameter($etag),
				'next_client' => $qb->createNamedParameter(2, IQueryBuilder::PARAM_INT),
			])->executeStatement();
		}
		$session = $this->sessionByFile($fileId);
		if ($session === null) {
			throw new \RuntimeException('co-editing session vanished');
		}
		$id = (int)$session['id'];
		$client = (int)$session['next_client'] - 1;
		if ($client > self::MAX_CLIENT) {
			throw new \RuntimeException('co-editing: out of client ids for this file');
		}

		if ($session['checkpoint_etag'] !== $etag) {
			if ($this->activeMembers($id, $now) !== []) {
				// Saved for the session since the joiner loaded it: load it again.
				return ['retry' => true];
			}
			// Nobody's here and the file changed (outside the session, or a session long gone):
			// the file as it is now is where the log starts.
			$qb = $this->db->getQueryBuilder();
			$qb->delete('gridcraft_ops')->where($qb->expr()->eq('session_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('gridcraft_members')->where($qb->expr()->eq('session_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->update('gridcraft_sessions')
				->set('checkpoint_seq', 'last_seq')
				->set('checkpoint_etag', $qb->createNamedParameter($etag))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$session = $this->sessionByFile($fileId) ?? $session;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert('gridcraft_members')->values([
			'session_id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT),
			'client' => $qb->createNamedParameter($client, IQueryBuilder::PARAM_INT),
			'user_id' => $qb->createNamedParameter($userId),
			'display_name' => $qb->createNamedParameter(mb_substr($displayName, 0, 255)),
			'last_seen' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();

		$from = (int)$session['checkpoint_seq'];
		return [
			'sessionId' => $id,
			'client' => $client,
			'seq' => $from,
			'entries' => $this->entries($id, $from, PHP_INT_MAX),
			'members' => $this->activeMembers($id, $now),
		];
	}

	/**
	 * Appends an op to the log and returns its position. A retried push (the same client and
	 * client sequence number) gets the position it was given the first time.
	 */
	public function push(int $sessionId, int $client, string $userId, int $clientSeq, int $base, string $op): int {
		$this->requireMember($sessionId, $client, $userId);
		if (strlen($op) > self::MAX_OP_BYTES) {
			throw new \InvalidArgumentException('the edit is too large to share');
		}
		$decoded = json_decode($op);
		if (!is_object($decoded)) {
			throw new \InvalidArgumentException('the edit is not a JSON object');
		}
		$existing = $this->opSeq($sessionId, $client, $clientSeq);
		if ($existing !== null) {
			return $existing;
		}
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('gridcraft_sessions')
				->set('last_seq', $qb->createFunction('last_seq + 1'))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$seq = (int)($this->sessionById($sessionId)['last_seq'] ?? 0);
			if ($base >= $seq) {
				throw new \InvalidArgumentException('the edit was made against a log position that does not exist yet');
			}
			$qb = $this->db->getQueryBuilder();
			$qb->insert('gridcraft_ops')->values([
				'session_id' => $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT),
				'seq' => $qb->createNamedParameter($seq, IQueryBuilder::PARAM_INT),
				'client' => $qb->createNamedParameter($client, IQueryBuilder::PARAM_INT),
				'client_seq' => $qb->createNamedParameter($clientSeq, IQueryBuilder::PARAM_INT),
				'base' => $qb->createNamedParameter($base, IQueryBuilder::PARAM_INT),
				'op' => $qb->createNamedParameter($op),
			])->executeStatement();
			$this->db->commit();
			return $seq;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			// The same push racing itself: the other attempt logged it.
			$again = $this->opSeq($sessionId, $client, $clientSeq);
			if ($again !== null) {
				return $again;
			}
			throw $e;
		}
	}

	/**
	 * The log entries after `$since` (up to MAX_BATCH), who's in the session and where, and where
	 * the file was last saved. `$selection` is this member's position now (JSON, or '' if
	 * unchanged). `['rejoin' => true]` if the entries after `$since` are no longer kept.
	 */
	public function sync(int $sessionId, int $client, string $userId, int $since, string $selection = ''): array {
		$this->requireMember($sessionId, $client, $userId);
		$now = $this->time->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->update('gridcraft_members')
			->set('last_seen', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		if ($selection !== '' && strlen($selection) <= self::MAX_SELECTION_BYTES && is_object(json_decode($selection))) {
			$qb->set('selection', $qb->createNamedParameter($selection));
		}
		$qb->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('client', $qb->createNamedParameter($client, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$session = $this->sessionById($sessionId);
		$entries = $this->entries($sessionId, $since, self::MAX_BATCH);
		$last = (int)($session['last_seq'] ?? 0);
		if ($since < $last && ($entries === [] || (int)$entries[0]['seq'] !== $since + 1)) {
			return ['rejoin' => true];
		}
		return [
			'entries' => $entries,
			'members' => $this->activeMembers($sessionId, $now),
			'checkpointSeq' => (int)($session['checkpoint_seq'] ?? 0),
			'checkpointEtag' => (string)($session['checkpoint_etag'] ?? ''),
		];
	}

	/** Records that the file was saved for the session at log position `$seq` with `$etag`. */
	public function checkpoint(int $sessionId, int $client, string $userId, int $seq, string $etag): void {
		$this->requireMember($sessionId, $client, $userId);
		$qb = $this->db->getQueryBuilder();
		$qb->update('gridcraft_sessions')
			->set('checkpoint_seq', $qb->createNamedParameter($seq, IQueryBuilder::PARAM_INT))
			->set('checkpoint_etag', $qb->createNamedParameter(self::bareEtag($etag)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('checkpoint_seq', $qb->createNamedParameter($seq, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte($qb->createNamedParameter($seq, IQueryBuilder::PARAM_INT), 'last_seq'))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('gridcraft_ops')
			->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('seq', $qb->createNamedParameter($seq - self::KEEP_BEFORE_CHECKPOINT, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function leave(int $sessionId, int $client, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('gridcraft_members')
			->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('client', $qb->createNamedParameter($client, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}

	/** The file a session is for, or null. */
	public function fileOf(int $sessionId): ?int {
		$s = $this->sessionById($sessionId);
		return $s === null ? null : (int)$s['file_id'];
	}

	/** An ETag without the quotes WebDAV puts around it. */
	public static function bareEtag(string $etag): string {
		return trim($etag, " \t\"");
	}

	private function requireMember(int $sessionId, int $client, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('id')->from('gridcraft_members')
			->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('client', $qb->createNamedParameter($client, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeQuery()->fetch();
		if ($row === false) {
			throw new NotMemberException();
		}
	}

	/** @return list<array{seq: int, client: int, client_seq: int, base: int, op: string}> */
	private function entries(int $sessionId, int $since, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('seq', 'client', 'client_seq', 'base', 'op')->from('gridcraft_ops')
			->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('seq', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('seq', 'ASC');
		if ($limit < PHP_INT_MAX) {
			$qb->setMaxResults($limit);
		}
		$out = [];
		$rows = $qb->executeQuery();
		while ($r = $rows->fetch()) {
			$out[] = ['seq' => (int)$r['seq'], 'client' => (int)$r['client'], 'client_seq' => (int)$r['client_seq'], 'base' => (int)$r['base'], 'op' => (string)$r['op']];
		}
		$rows->closeCursor();
		return $out;
	}

	/** @return list<array{client: int, userId: string, displayName: string}> */
	private function activeMembers(int $sessionId, int $now): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('client', 'user_id', 'display_name', 'selection')->from('gridcraft_members')
			->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('last_seen', $qb->createNamedParameter($now - self::ACTIVE_SECONDS, IQueryBuilder::PARAM_INT)))
			->orderBy('client', 'ASC');
		$out = [];
		$rows = $qb->executeQuery();
		while ($r = $rows->fetch()) {
			$out[] = [
				'client' => (int)$r['client'],
				'userId' => (string)$r['user_id'],
				'displayName' => (string)$r['display_name'],
				'selection' => (string)($r['selection'] ?? ''),
			];
		}
		$rows->closeCursor();
		return $out;
	}

	private function opSeq(int $sessionId, int $client, int $clientSeq): ?int {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('seq')->from('gridcraft_ops')
			->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('client', $qb->createNamedParameter($client, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('client_seq', $qb->createNamedParameter($clientSeq, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetch();
		return $row === false ? null : (int)$row['seq'];
	}

	private function sessionByFile(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('*')->from('gridcraft_sessions')
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetch();
		return $row === false ? null : $row;
	}

	private function sessionById(int $id): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('*')->from('gridcraft_sessions')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetch();
		return $row === false ? null : $row;
	}
}
