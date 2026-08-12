<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Explicit migration fixtures for cross-package tests. */
final class RuntimeSchemaMigrations
{
    public static function broadcast(DBALDatabase $database): void
    {
        self::apply($database, 'packages/api/migrations/2026_08_12_000001_broadcast_schema.php');
    }

    public static function auth(DBALDatabase $database): void
    {
        self::apply($database, 'packages/auth/migrations/2026_08_12_000001_auth_runtime_schema.php');
    }

    public static function oidc(DBALDatabase $database): void
    {
        foreach ([
            '2026_04_26_000001_oidc_client_schema.php',
            '2026_05_25_000002_oidc_token_schema.php',
            '2026_05_25_000003_oidc_signing_key_schema.php',
            '2026_05_25_000004_oidc_user_consent_schema.php',
            '2026_07_15_000005_oidc_secret_storage.php',
            '2026_08_12_000006_oidc_authorization_code_schema.php',
        ] as $migration) {
            self::apply($database, 'packages/oidc/migrations/' . $migration);
        }
    }

    public static function publishing(DBALDatabase $database): void
    {
        self::apply($database, 'packages/publishing/migrations/2026_08_12_000001_idempotency_schema.php');
    }

    public static function state(DBALDatabase $database): void
    {
        self::apply($database, 'packages/state/migrations/2026_08_12_000001_state_schema.php');
    }

    /**
     * Installs the relationship shape owned by the coordinated entity-schema plan.
     *
     * This is deliberately a test fixture rather than a package migration: the
     * relationship table is an entity type whose base table is created by the
     * entity schema coordinator.
     */
    public static function relationship(DBALDatabase $database): void
    {
        if (!$database->schema()->tableExists('relationship')) {
            $database->schema()->createTable('relationship', [
                'fields' => [
                    'rid' => ['type' => 'serial', 'not null' => true],
                    'uuid' => ['type' => 'varchar', 'length' => 128],
                    'relationship_type' => ['type' => 'varchar', 'length' => 128, 'not null' => true],
                ],
                'primary key' => ['rid'],
            ]);
        }

        $schema = $database->schema();
        $fields = [
            'uuid' => ['type' => 'varchar', 'length' => 128],
            'from_entity_type' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
            'from_entity_id' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
            'to_entity_type' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
            'to_entity_id' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
            'directionality' => ['type' => 'varchar', 'length' => 16, 'not null' => true, 'default' => 'directed'],
            'status' => ['type' => 'int', 'not null' => true, 'default' => 1],
            'weight' => ['type' => 'float'],
            'start_date' => ['type' => 'int'],
            'end_date' => ['type' => 'int'],
            'confidence' => ['type' => 'float'],
            'source_ref' => ['type' => 'text'],
            'notes' => ['type' => 'text'],
        ];
        foreach ($fields as $name => $definition) {
            if (!$schema->fieldExists('relationship', $name)) {
                $schema->addField('relationship', $name, $definition);
            }
        }

        $existingIndexes = [];
        foreach ($database->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'relationship'") as $row) {
            $existingIndexes[] = $row['name'];
        }
        foreach ([
            'relationship_from_status_idx' => ['from_entity_type', 'from_entity_id', 'status'],
            'relationship_to_status_idx' => ['to_entity_type', 'to_entity_id', 'status'],
            'relationship_type_status_idx' => ['relationship_type', 'status'],
            'relationship_temporal_idx' => ['start_date', 'end_date'],
        ] as $name => $columns) {
            if (!in_array($name, $existingIndexes, true)) {
                $schema->addIndex('relationship', $name, $columns);
            }
        }
    }

    private static function apply(DBALDatabase $database, string $relativePath): void
    {
        $migration = require dirname(__DIR__, 2) . '/' . $relativePath;
        if (!$migration instanceof Migration) {
            throw new \LogicException(sprintf('Runtime migration "%s" is invalid.', $relativePath));
        }
        $migration->up(new SchemaBuilder($database->getConnection()));
    }
}
