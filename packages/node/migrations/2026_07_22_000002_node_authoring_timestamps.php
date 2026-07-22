<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * Backfill authoring timestamps omitted by pre-fix Admin SPA node creates.
 *
 * Node's sql-blob storage keeps both values in `_data`; imported rows already
 * carrying either timestamp are preserved byte-for-byte for that value. The
 * migration is deliberately idempotent so interrupted or repeated deploys do
 * not move a repaired node forward in time.
 */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasTable('node') || !$schema->hasColumn('node', '_data') || !$schema->hasColumn('node', 'nid')) {
            return;
        }

        $connection = $schema->getConnection();
        $platform = $connection->getDatabasePlatform();
        $node = $platform->quoteIdentifier('node');
        $nid = $platform->quoteIdentifier('nid');
        $dataColumn = $platform->quoteIdentifier('_data');
        $backfillTimestamp = time();

        foreach ($connection->fetchAllAssociative(sprintf('SELECT %s, %s FROM %s', $nid, $dataColumn, $node)) as $row) {
            $data = json_decode((string) ($row['_data'] ?? '{}'), true);
            if (!is_array($data)) {
                continue;
            }

            $changed = false;
            foreach (['created', 'changed'] as $field) {
                $value = $data[$field] ?? null;
                if ($value === null || $value === '' || $value === 0 || $value === '0') {
                    $data[$field] = $backfillTimestamp;
                    $changed = true;
                }
            }
            if (!$changed) {
                continue;
            }

            $connection->executeStatement(
                sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $node, $dataColumn, $nid),
                [json_encode($data, JSON_THROW_ON_ERROR), $row['nid']],
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Data repair: restoring absent timestamps would reintroduce the defect.
    }
};
