<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\TextType;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversNothing]
final class OidcClientSchemaMigrationTest extends TestCase
{
    #[Test]
    public function oidcPackageMigrationIsRegisteredAndCreatesExpectedColumns(): void
    {
        $pkgRoot = dirname(__DIR__, 2);
        $migrationsDir = $pkgRoot . '/migrations';
        $monorepoRoot = dirname(__DIR__, 4);

        $manifest = new PackageManifest(migrations: [
            'waaseyaa/oidc' => $migrationsDir,
        ]);

        // Use monorepo root as basePath so MigrationLoader's app `migrations/` pass
        // does not scan the same directory as the OIDC package entry.
        $loader = new MigrationLoader($monorepoRoot, $manifest);
        $all = $loader->loadAll();

        self::assertArrayHasKey('waaseyaa/oidc', $all);
        $name = 'waaseyaa/oidc:2026_04_26_000001_oidc_client_schema';
        self::assertArrayHasKey($name, $all['waaseyaa/oidc']);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();

        $migrator = new Migrator($connection, $repository);
        $result = $migrator->run($all);
        self::assertSame(8, $result->count);

        $schema = new SchemaBuilder($connection);
        self::assertTrue($schema->hasTable('oidc_client'));
        self::assertTrue($schema->hasColumn('oidc_client', 'client_id'));
        self::assertTrue($schema->hasColumn('oidc_client', 'is_confidential'));
        self::assertTrue($schema->hasColumn('oidc_client', 'client_secret_hash'));
        self::assertTrue($schema->hasColumn('oidc_access_token', 'token_lookup'));
        self::assertTrue($schema->hasColumn('oidc_access_token', 'custody_sequence'));
        self::assertTrue($schema->hasColumn('oidc_refresh_token', 'token_lookup'));
        self::assertTrue($schema->hasColumn('oidc_refresh_token', 'custody_sequence'));
        self::assertTrue($schema->hasTable('oidc_token_custody_sequence'));
        self::assertTrue($schema->hasColumn('oidc_authorization_codes', 'nonce'));
        self::assertTrue($schema->hasColumn('oidc_signing_key', 'key_version'));
        self::assertTrue($schema->hasColumn('oidc_signing_key', 'state'));
        self::assertTrue($schema->hasTable('oidc_signing_key_version_sequence'));
        self::assertTrue($schema->hasTable('oidc_signing_key_revocation'));

        $schemaManager = $connection->createSchemaManager();
        foreach (['oidc_access_token', 'oidc_refresh_token'] as $table) {
            $columns = $schemaManager->listTableColumns($table);
            self::assertInstanceOf(TextType::class, $columns['token']->getType());
            self::assertTrue($columns['custody_sequence']->getNotnull());

            $uniqueColumns = array_map(
                static fn($index): array => $index->isUnique() ? $index->getColumns() : [],
                $schemaManager->listTableIndexes($table),
            );
            self::assertContains(['custody_sequence'], $uniqueColumns);
        }

        $result2 = $migrator->run($all);
        self::assertSame(0, $result2->count);
    }
}
