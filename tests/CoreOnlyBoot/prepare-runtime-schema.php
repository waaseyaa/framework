<?php

declare(strict_types=1);

/**
 * Prepare only the runtime schema required to boot the core metapackage.
 *
 * The packaged composition proof is about provider/capability wiring, not the
 * schema coordinator. Runtime repositories correctly refuse an empty database,
 * so this disposable consumer applies the installed audit migrations explicitly
 * before exercising the kernel. No application or repository test helpers are
 * available inside the core-only consumer.
 */

require __DIR__ . '/vendor/autoload.php';

$databasePath = __DIR__ . '/storage/core-only.sqlite';
putenv('WAASEYAA_DB=' . $databasePath);

$database = \Waaseyaa\Database\DBALDatabase::createSqlite($databasePath, 'testing');
$schema = new \Waaseyaa\Foundation\Migration\SchemaBuilder($database->getConnection());

foreach ([
    '2026_08_12_000003_audit_runtime_schema.php',
    '2026_08_12_000004_strict_audit_ledger_schema.php',
    '2026_08_12_000005_approval_event_schema.php',
] as $migrationName) {
    $migration = require __DIR__ . '/vendor/waaseyaa/audit/migrations/' . $migrationName;
    if (!$migration instanceof \Waaseyaa\Foundation\Migration\Migration) {
        throw new \RuntimeException(sprintf('Invalid installed audit migration: %s', $migrationName));
    }

    $migration->up($schema);
}

$database->getConnection()->close();

