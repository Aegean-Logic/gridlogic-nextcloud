<?php

declare(strict_types=1);

namespace OCA\GridCraft\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Co-editing presence: where each member is (their sheet and selection, as JSON).
 */
class Version000300Date20261017000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable('gridcraft_members');
		if (!$t->hasColumn('selection')) {
			$t->addColumn('selection', Types::TEXT, ['notnull' => false]);
			return $schema;
		}
		return null;
	}
}
