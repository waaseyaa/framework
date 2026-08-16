<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Migration\TableBuilder;

/** DB-02-owned inventory and storage shape for OIDC application-master custody. */
return new class extends Migration {
    private const array TOKEN_TABLES = [
        'oidc_access_token' => 'access',
        'oidc_refresh_token' => 'refresh',
    ];

    public function up(SchemaBuilder $schema): void
    {
        $connection = $schema->getConnection();
        $this->addNullableInventoryAndWidenCiphertext($connection);
        $nextSequences = $this->backfillInventory($connection);
        $this->requireInventoryAndIndexIt($connection);

        if (!$schema->hasTable('oidc_token_custody_sequence')) {
            $schema->create('oidc_token_custody_sequence', static function (TableBuilder $table): void {
                $table->string('token_kind', 16);
                $table->integer('next_sequence');
                $table->primary(['token_kind']);
            });
        }

        foreach ($nextSequences as $kind => $nextSequence) {
            $exists = $connection->fetchOne(
                'SELECT COUNT(*) FROM oidc_token_custody_sequence WHERE token_kind = ?',
                [$kind],
            );
            if ((int) $exists === 0) {
                $connection->insert('oidc_token_custody_sequence', [
                    'token_kind' => $kind,
                    'next_sequence' => $nextSequence,
                ]);
            }
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only custody migration.
    }

    private function addNullableInventoryAndWidenCiphertext(Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();
        $current = $schemaManager->introspectSchema();
        $next = clone $current;
        $changed = false;

        foreach (array_keys(self::TOKEN_TABLES) as $tableName) {
            if (!$next->hasTable($tableName)) {
                continue;
            }
            $table = $next->getTable($tableName);
            if (!$table->hasColumn('custody_sequence')) {
                $table->addColumn('custody_sequence', Types::INTEGER, ['notnull' => false]);
                $changed = true;
            }
            if (!$table->getColumn('token')->getType() instanceof \Doctrine\DBAL\Types\TextType) {
                $table->modifyColumn('token', [
                    'type' => Type::getType(Types::TEXT),
                    'length' => null,
                ]);
                $changed = true;
            }
            foreach ($table->getIndexes() as $index) {
                if (!$index->isPrimary()
                    && $index->isUnique()
                    && $index->getColumns() === ['token']) {
                    $table->dropIndex($index->getName());
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $this->applySchemaDiff($connection, $current, $next);
        }
    }

    /** @return array<string, int> */
    private function backfillInventory(Connection $connection): array
    {
        $nextSequences = [];
        foreach (self::TOKEN_TABLES as $tableName => $kind) {
            $sequence = 1;
            $rows = $connection->fetchAllAssociative(
                sprintf('SELECT jti, custody_sequence FROM %s ORDER BY jti ASC', $tableName),
            );
            foreach ($rows as $row) {
                $existing = $row['custody_sequence'];
                if ($existing === null) {
                    $updated = $connection->update(
                        $tableName,
                        ['custody_sequence' => $sequence],
                        ['jti' => (string) $row['jti']],
                    );
                    if ($updated !== 1) {
                        throw new \RuntimeException('OIDC custody inventory backfill conflicted.');
                    }
                } else {
                    $sequence = max($sequence, (int) $existing);
                }
                ++$sequence;
            }
            $nextSequences[$kind] = $sequence;
        }

        return $nextSequences;
    }

    private function requireInventoryAndIndexIt(Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();
        $current = $schemaManager->introspectSchema();
        $next = clone $current;
        $changed = false;

        foreach (array_keys(self::TOKEN_TABLES) as $tableName) {
            $table = $next->getTable($tableName);
            if (!$table->getColumn('custody_sequence')->getNotnull()) {
                $table->modifyColumn('custody_sequence', ['notnull' => true]);
                $changed = true;
            }
            $hasUnique = false;
            foreach ($table->getIndexes() as $index) {
                if ($index->isUnique() && $index->getColumns() === ['custody_sequence']) {
                    $hasUnique = true;
                    break;
                }
            }
            if (!$hasUnique) {
                $table->addUniqueIndex(
                    ['custody_sequence'],
                    'idx_' . $tableName . '_custody_sequence',
                );
                $changed = true;
            }
        }

        if ($changed) {
            $this->applySchemaDiff($connection, $current, $next);
        }
    }

    private function applySchemaDiff(
        Connection $connection,
        \Doctrine\DBAL\Schema\Schema $current,
        \Doctrine\DBAL\Schema\Schema $next,
    ): void {
        $schemaManager = $connection->createSchemaManager();
        $diff = $schemaManager->createComparator()->compareSchemas($current, $next);
        foreach ($connection->getDatabasePlatform()->getAlterSchemaSQL($diff) as $sql) {
            $connection->executeStatement($sql);
        }
    }
};
