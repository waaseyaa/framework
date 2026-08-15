<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Support;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/** Explicit migration-source setup for OIDC tests; never used by runtime code. */
final class OidcSchema
{
    public static function installAuthorizationCodes(DBALDatabase $database): void
    {
        self::apply($database, '2026_08_12_000006_oidc_authorization_code_schema.php');
    }

    public static function installSigningKeys(DBALDatabase $database): void
    {
        self::apply($database, '2026_05_25_000003_oidc_signing_key_schema.php');
        self::apply($database, '2026_08_15_000007_oidc_signing_key_lifecycle.php');
    }

    public static function installTokenStorage(DBALDatabase $database): void
    {
        self::apply($database, '2026_05_25_000002_oidc_token_schema.php');
        self::apply($database, '2026_07_15_000005_oidc_secret_storage.php');
    }

    private static function apply(DBALDatabase $database, string $file): void
    {
        $migration = require dirname(__DIR__, 2) . '/migrations/' . $file;
        if (!$migration instanceof Migration) {
            throw new \LogicException(sprintf('OIDC migration "%s" is invalid.', $file));
        }

        $migration->up(new SchemaBuilder($database->getConnection()));
    }
}
