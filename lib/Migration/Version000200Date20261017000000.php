<?php

declare(strict_types=1);

namespace OCA\GridCraft\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Co-editing: one session per shared file, its op log, and who's in it.
 */
class Version000200Date20261017000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('gridcraft_sessions')) {
			$t = $schema->createTable('gridcraft_sessions');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// The last log position handed out.
			$t->addColumn('last_seq', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			// The file as last saved for the session: its log position and ETag.
			$t->addColumn('checkpoint_seq', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$t->addColumn('checkpoint_etag', Types::STRING, ['notnull' => true, 'default' => '', 'length' => 64]);
			$t->addColumn('next_client', Types::BIGINT, ['notnull' => true, 'default' => 1, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['file_id'], 'gc_sessions_file');
		}

		if (!$schema->hasTable('gridcraft_ops')) {
			$t = $schema->createTable('gridcraft_ops');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('session_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('seq', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('client', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('client_seq', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('base', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// The op as JSON text, passed through untouched.
			$t->addColumn('op', Types::TEXT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['session_id', 'seq'], 'gc_ops_seq');
			// A retried push is recognised, not logged twice.
			$t->addUniqueIndex(['session_id', 'client', 'client_seq'], 'gc_ops_client');
		}

		if (!$schema->hasTable('gridcraft_members')) {
			$t = $schema->createTable('gridcraft_members');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('session_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('client', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('display_name', Types::STRING, ['notnull' => true, 'default' => '', 'length' => 255]);
			$t->addColumn('last_seen', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['session_id', 'client'], 'gc_members_client');
		}
		return $schema;
	}
}
