<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Migration;

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
final class InfrastructureRuntimeSchemaMigrationTest extends TestCase
{
    #[Test]
    public function package_declared_migrations_install_the_infrastructure_schema_family(): void
    {
        $root = dirname(__DIR__, 3);
        $packages = ['api', 'cache', 'foundation', 'publishing', 'state'];
        $paths = [];
        foreach ($packages as $package) {
            $composer = json_decode(
                (string) file_get_contents(sprintf('%s/packages/%s/composer.json', $root, $package)),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertSame('migrations', $composer['extra']['waaseyaa']['migrations'] ?? null);
            $paths['waaseyaa/' . $package] = sprintf('%s/packages/%s/migrations', $root, $package);
        }

        $all = new MigrationLoader($root, new PackageManifest(migrations: $paths))->loadAll();
        self::assertSame([
            'waaseyaa/api:2026_08_12_000001_broadcast_schema',
            'waaseyaa/cache:2026_08_12_000001_cache_items_schema',
            'waaseyaa/cache:2026_08_15_000002_cache_generation',
            'waaseyaa/foundation:2026_08_12_000001_rate_limit_window_schema',
            'waaseyaa/foundation:2026_08_15_000002_application_master_rekey',
            'waaseyaa/publishing:2026_08_12_000001_idempotency_schema',
            'waaseyaa/state:2026_08_12_000001_state_schema',
        ], array_merge(...array_values(array_map('array_keys', $all))));

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migrator = new Migrator($connection, new MigrationRepository($connection));
        self::assertSame(7, $migrator->run($all)->count);

        $schema = new SchemaBuilder($connection);
        foreach ([
            '_broadcast_log',
            '_broadcast_retained',
            'cache_generation',
            'cache_items',
            'publishing_idempotency',
            'rate_limit_windows',
            'state',
            'waaseyaa_application_master_rekey',
            'waaseyaa_application_master_rekey_adapter',
            'waaseyaa_application_master_rekey_event',
            'waaseyaa_application_master_rekey_failure',
            'waaseyaa_application_master_rekey_gate',
            'waaseyaa_application_master_rekey_purpose',
            'waaseyaa_application_master_rekey_rollback_verification',
            'waaseyaa_application_master_rekey_verification',
            'waaseyaa_application_master_version',
        ] as $table) {
            self::assertTrue($schema->hasTable($table), sprintf('Migration did not install "%s".', $table));
        }
        self::assertSame(0, $migrator->run($all)->count);
    }
}
