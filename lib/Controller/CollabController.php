<?php

declare(strict_types=1);

namespace OCA\GridCraft\Controller;

use OCA\GridCraft\AppInfo\Application;
use OCA\GridCraft\Service\CollabService;
use OCA\GridCraft\Service\NotMemberException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Co-editing endpoints for the editor page. Every call checks the user can still read the file
 * (and, to push edits, write it).
 */
class CollabController extends Controller {
	use CollabResponses;

	public function __construct(
		IRequest $request,
		private CollabService $collab,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/collab/join')]
	public function join(int $fileId, string $etag): DataResponse {
		return $this->guard(function () use ($fileId, $etag) {
			$user = $this->userSession->getUser();
			$node = $this->file($fileId);
			$canEdit = $node->isUpdateable();
			$result = $this->collab->join($fileId, $etag, $user->getUID(), $user->getDisplayName(), $canEdit);
			$result['canEdit'] = $canEdit;
			return $result;
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/collab/push')]
	public function push(int $sessionId, int $client, int $clientSeq, int $base, string $op): DataResponse {
		return $this->guard(function () use ($sessionId, $client, $clientSeq, $base, $op) {
			$node = $this->sessionFile($sessionId);
			if (!$node->isUpdateable()) {
				return self::viewOnly();
			}
			return ['seq' => $this->collab->push($sessionId, $client, $this->uid(), $clientSeq, $base, $op)];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/collab/sync')]
	public function sync(int $sessionId, int $client, int $since, string $sel = ''): DataResponse {
		return $this->guard(function () use ($sessionId, $client, $since, $sel) {
			$this->sessionFile($sessionId);
			return $this->collab->sync($sessionId, $client, $this->uid(), $since, $sel);
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/collab/checkpoint')]
	public function checkpoint(int $sessionId, int $client, int $seq, string $etag, string $sheetIds = ''): DataResponse {
		return $this->guard(function () use ($sessionId, $client, $seq, $etag, $sheetIds) {
			$this->sessionFile($sessionId);
			$this->collab->checkpoint($sessionId, $client, $this->uid(), $seq, $etag, $sheetIds);
			return [];
		});
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/collab/leave')]
	public function leave(int $sessionId, int $client): DataResponse {
		return $this->guard(function () use ($sessionId, $client) {
			$this->collab->leave($sessionId, $client, $this->uid());
			return [];
		});
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? throw new NotAllowed('Sign in to edit.');
	}

	/** The file `$fileId` as the signed-in user sees it. */
	private function file(int $fileId): Node {
		$node = $this->rootFolder->getUserFolder($this->uid())->getFirstNodeById($fileId);
		if ($node === null || !$node->isReadable()) {
			throw new NotAllowed('The file is not available.');
		}
		return $node;
	}

	private function sessionFile(int $sessionId): Node {
		$fileId = $this->collab->fileOf($sessionId) ?? throw new NotMemberException();
		return $this->file($fileId);
	}
}

