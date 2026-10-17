<?php

declare(strict_types=1);

namespace OCA\GridCraft\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Co-editing: the sheet ids of the last checkpoint (JSON). XLSX doesn't keep them, and someone
 * joining from the saved file needs the ids the session knows its sheets by.
 */
class Version000600Date20261017000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable('gridcraft_sessions');
		if (!$t->hasColumn('checkpoint_sheets')) {
			$t->addColumn('checkpoint_sheets', Types::TEXT, ['notnull' => false]);
			return $schema;
		}
		return null;
	}
}
