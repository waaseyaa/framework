<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversNothing]
final class AuthRuntimeSchemaMigrationTest extends TestCase
{
    #[Test]
    public function package_declared_migrations_install_the_complete_auth_family(): void
    {
        $root = dirname(__DIR__, 4);
        $authComposer = json_decode(
            (string) file_get_contents($root . '/packages/auth/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $foundationComposer = json_decode(
            (string) file_get_contents($root . '/packages/foundation/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('migrations', $authComposer['extra']['waaseyaa']['migrations'] ?? null);
        self::assertSame('migrations', $foundationComposer['extra']['waaseyaa']['migrations'] ?? null);

        $manifest = new PackageManifest(migrations: [
            'waaseyaa/auth' => $root . '/packages/auth/migrations',
            'waaseyaa/foundation' => $root . '/packages/foundation/migrations',
        ]);
        $all = new MigrationLoader($root, $manifest)->loadAll();

        self::assertArrayHasKey(
            'waaseyaa/auth:2026_08_12_000001_auth_runtime_schema',
            $all['waaseyaa/auth'] ?? [],
        );
        self::assertArrayHasKey(
            'waaseyaa/foundation:2026_08_12_000001_rate_limit_window_schema',
            $all['waaseyaa/foundation'] ?? [],
        );
        self::assertArrayHasKey(
            'waaseyaa/foundation:2026_08_15_000002_application_master_rekey',
            $all['waaseyaa/foundation'] ?? [],
        );

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $result = new Migrator($connection, new MigrationRepository($connection))->run($all);
        self::assertSame(3, $result->count);

        $schema = new SchemaBuilder($connection);
        foreach ([
            'auth_tokens',
            'auth_bearer_token',
            'rate_limits',
            'rate_limit_windows',
            'waaseyaa_application_master_rekey',
            'waaseyaa_application_master_version',
        ] as $table) {
            self::assertTrue($schema->hasTable($table), sprintf('Migration did not install "%s".', $table));
        }
        self::assertTrue($schema->hasColumn('auth_bearer_token', 'rotated_from'));
        self::assertTrue($schema->hasColumn('auth_tokens', 'created_by'));

        self::assertSame(0, new Migrator($connection, new MigrationRepository($connection))->run($all)->count);
    }
}
