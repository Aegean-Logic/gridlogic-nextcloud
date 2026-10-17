<?php

declare(strict_types=1);

namespace OCA\GridCraft\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Co-editing: whether each member can edit (only editors save the shared workbook to the file).
 */
class Version000400Date20261017000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable('gridcraft_members');
		if (!$t->hasColumn('can_edit')) {
			$t->addColumn('can_edit', Types::SMALLINT, ['notnull' => true, 'default' => 1, 'unsigned' => true]);
			return $schema;
		}
		return null;
	}
}
