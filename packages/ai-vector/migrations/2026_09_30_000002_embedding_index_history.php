<?php

declare(strict_types=1);

use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\Types;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Conservatively adopts existing generations and legacy vector identities. */
return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasTable('embedding_generations') || !$schema->hasTable('embeddings')) {
            throw new \LogicException('[AIV-EXECUTION-006] Apply embedding storage and generation migrations first.');
        }
        $connection = $schema->getConnection();
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable('embedding_generations');
        if (!$before->hasColumn('potentially_indexed')) {
            $after = clone $before;
            $after->addColumn('potentially_indexed', Types::INTEGER, ['notnull' => true, 'default' => 1]);
            $manager->alterTable($manager->createComparator()->compareTables($before, $after));
        } else {
            $column = $before->getColumn('potentially_indexed');
            if (!$column->getType() instanceof IntegerType || !$column->getNotnull()) {
                throw new \UnexpectedValueException('[AIV-EXECUTION-010] Embedding indexing history schema is corrupt.');
            }
        }
        $invalidRows = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM embedding_generations WHERE potentially_indexed IS NULL OR potentially_indexed NOT IN (0, 1)',
        );
        if ($invalidRows !== 0) {
            throw new \UnexpectedValueException('[AIV-EXECUTION-010] Embedding indexing history is corrupt.');
        }
        foreach ($connection->iterateAssociative('SELECT entity_type, entity_id FROM embeddings') as $row) {
            $connection->executeStatement(
                'INSERT INTO embedding_generations (entity_type, entity_id, token, potentially_indexed) VALUES (?, ?, ?, 1) '
                . 'ON CONFLICT (entity_type, entity_id) DO UPDATE SET potentially_indexed = 1',
                [$row['entity_type'], $row['entity_id'], bin2hex(random_bytes(16))],
            );
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        // Forward-only: indexing history and generation tombstones remain authoritative.
    }
};
