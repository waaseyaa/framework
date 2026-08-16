<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Support;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Explicit migration-source setup for auth tests; never used by runtime code. */
final class AuthSchema
{
    public static function install(DBALDatabase $database): void
    {
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_08_12_000001_auth_runtime_schema.php';
        if (!$migration instanceof Migration) {
            throw new \LogicException('The auth runtime migration is invalid.');
        }

        $migration->up(new SchemaBuilder($database->getConnection()));
    }
}
