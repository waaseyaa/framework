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
        $backfillTimestamp = time();
        $this->repairTable($connection, 'node', ['nid'], $backfillTimestamp);

        if ($schema->hasTable('node_revision')
            && $schema->hasColumn('node_revision', '_data')
            && $schema->hasColumn('node_revision', 'entity_id')
            && $schema->hasColumn('node_revision', 'revision_id')) {
            $this->repairTable($connection, 'node_revision', ['entity_id', 'revision_id'], $backfillTimestamp);
        }
    }

    /** @param list<string> $identityColumns */
    private function repairTable(\Doctrine\DBAL\Connection $connection, string $tableName, array $identityColumns, int $backfillTimestamp): void
    {
        $platform = $connection->getDatabasePlatform();
        $table = $platform->quoteIdentifier($tableName);
        $dataColumn = $platform->quoteIdentifier('_data');
        $quotedIdentity = array_map($platform->quoteIdentifier(...), $identityColumns);
        $selectColumns = implode(', ', [...$quotedIdentity, $dataColumn]);

        foreach ($connection->fetchAllAssociative(sprintf('SELECT %s FROM %s', $selectColumns, $table)) as $row) {
            $data = json_decode((string) ($row['_data'] ?? '{}'), true);
            if (!is_array($data)) {
                continue;
            }

            $hasCreated = $this->hasTimestamp($data['created'] ?? null);
            $hasChanged = $this->hasTimestamp($data['changed'] ?? null);
            if ($hasCreated && $hasChanged) {
                continue;
            }

            if (!$hasCreated) {
                $data['created'] = $hasChanged ? $data['changed'] : $backfillTimestamp;
            }
            if (!$hasChanged) {
                $data['changed'] = $data['created'];
            }

            $where = implode(' AND ', array_map(static fn(string $column): string => $column . ' = ?', $quotedIdentity));
            $identityValues = array_map(static fn(string $column): mixed => $row[$column], $identityColumns);
            $connection->executeStatement(
                sprintf('UPDATE %s SET %s = ? WHERE %s', $table, $dataColumn, $where),
                [json_encode($data, JSON_THROW_ON_ERROR), ...$identityValues],
            );
        }
    }

    private function hasTimestamp(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== 0 && $value !== '0';
    }

    public function down(SchemaBuilder $schema): void
    {
        // Data repair: restoring absent timestamps would reintroduce the defect.
    }
};
