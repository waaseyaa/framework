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
use Waaseyaa\Foundation\Migration\Migration;
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

    #[Test]
    public function applicationMasterCustodyMigrationBackfillsAStableInventory(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new SchemaBuilder($connection);
        $this->applyMigration($schema, '2026_05_25_000002_oidc_token_schema.php');
        $this->applyMigration($schema, '2026_07_15_000005_oidc_secret_storage.php');

        foreach (['token-b', 'token-a'] as $jti) {
            $connection->insert('oidc_access_token', [
                'jti' => $jti,
                'token' => 'legacy-envelope-' . $jti,
                'token_lookup' => hash('sha256', $jti),
                'client_id' => 'client',
                'account_id' => 'account',
                'scope' => 'openid',
                'issued_at' => 1,
                'expires_at' => 2,
                'revoked_at' => null,
            ]);
        }
        $connection->insert('oidc_refresh_token', [
            'jti' => 'refresh-z',
            'token' => 'refresh-legacy-envelope',
            'token_lookup' => hash('sha256', 'refresh-z'),
            'access_token_jti' => 'token-a',
            'client_id' => 'client',
            'account_id' => 'account',
            'scope' => 'openid',
            'auth_time' => 1,
            'chain_root_jti' => 'refresh-z',
            'issued_at' => 1,
            'expires_at' => 2,
            'revoked_at' => null,
        ]);

        $this->applyMigration($schema, '2026_08_15_000008_oidc_application_master_custody.php');

        self::assertSame([
            ['jti' => 'token-a', 'custody_sequence' => 1],
            ['jti' => 'token-b', 'custody_sequence' => 2],
        ], $connection->fetchAllAssociative(
            'SELECT jti, custody_sequence FROM oidc_access_token ORDER BY custody_sequence',
        ));
        self::assertSame([
            ['token_kind' => 'access', 'next_sequence' => 3],
            ['token_kind' => 'refresh', 'next_sequence' => 2],
        ], $connection->fetchAllAssociative(
            'SELECT token_kind, next_sequence FROM oidc_token_custody_sequence ORDER BY token_kind',
        ));

        $connection->insert('oidc_access_token', [
            'jti' => 'token-c',
            'token' => 'legacy-envelope-token-a',
            'token_lookup' => hash('sha256', 'token-c'),
            'custody_sequence' => 3,
            'client_id' => 'client',
            'account_id' => 'account',
            'scope' => 'openid',
            'issued_at' => 1,
            'expires_at' => 2,
            'revoked_at' => null,
        ]);
        $connection->update('oidc_access_token', [
            'token' => str_repeat('e', 512),
        ], ['jti' => 'token-c']);
        self::assertSame(512, (int) $connection->fetchOne(
            'SELECT LENGTH(token) FROM oidc_access_token WHERE jti = ?',
            ['token-c'],
        ));
    }

    private function applyMigration(SchemaBuilder $schema, string $file): void
    {
        $migration = require dirname(__DIR__, 2) . '/migrations/' . $file;
        self::assertInstanceOf(Migration::class, $migration);
        $migration->up($schema);
    }
}
