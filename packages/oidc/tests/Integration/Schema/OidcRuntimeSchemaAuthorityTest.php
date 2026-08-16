<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Oidc\Key\SigningKeyRepository;
use Waaseyaa\Oidc\Repository\DatabaseAuthorizationCodeRepository;
use Waaseyaa\Oidc\Security\LegacyOidcSecretMigrator;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\RefreshTokenIssuer;

/** Retained-red proof for migration-backed OIDC runtime stores. */
final class OidcRuntimeSchemaAuthorityTest extends TestCase
{
    #[Test]
    public function authorization_code_maintenance_refuses_missing_schema_without_installing_it(): void
    {
        $database = DBALDatabase::createSqlite();

        $this->assertMissingSchemaRefused(
            $database,
            'oidc_authorization_codes',
            static fn(): mixed => new DatabaseAuthorizationCodeRepository($database)->purgeExpired(),
        );
    }

    #[Test]
    public function signing_key_reads_refuse_missing_schema_without_installing_it(): void
    {
        $database = DBALDatabase::createSqlite();

        $this->assertMissingSchemaRefused(
            $database,
            'oidc_signing_key',
            static fn(): mixed => new SigningKeyRepository($database, random_bytes(32))->previousKey(),
        );
    }

    #[Test]
    public function access_token_reads_refuse_missing_schema_without_installing_it(): void
    {
        $database = DBALDatabase::createSqlite();

        $this->assertMissingSchemaRefused(
            $database,
            'oidc_access_token',
            static fn(): mixed => new AccessTokenIssuer($database, random_bytes(32), random_bytes(32))->findByJti('missing'),
        );
    }

    #[Test]
    public function refresh_token_reads_refuse_missing_schema_without_installing_it(): void
    {
        $database = DBALDatabase::createSqlite();

        $this->assertMissingSchemaRefused(
            $database,
            'oidc_refresh_token',
            static fn(): mixed => new RefreshTokenIssuer($database, random_bytes(32), random_bytes(32))->findByJti('missing'),
        );
    }

    #[Test]
    public function legacy_secret_migration_refuses_missing_db02_columns_without_lazy_ddl(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(
            'CREATE TABLE oidc_access_token (jti VARCHAR(128) PRIMARY KEY, token VARCHAR(128) NOT NULL)',
        );
        $keys = [random_bytes(32), random_bytes(32), random_bytes(32), random_bytes(32), random_bytes(32)];

        try {
            new LegacyOidcSecretMigrator($database, ...$keys)->migrate();
            self::fail('OIDC legacy migration must not install its own custody schema.');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString('S1-DB106', $failure->getMessage());
            self::assertStringContainsString('2026_08_15_000008', $failure->getMessage());
        }

        self::assertFalse($database->schema()->fieldExists('oidc_access_token', 'token_lookup'));
        self::assertFalse($database->schema()->fieldExists('oidc_access_token', 'custody_sequence'));
    }

    /** @param \Closure(): mixed $operation */
    private function assertMissingSchemaRefused(
        DBALDatabase $database,
        string $table,
        \Closure $operation,
    ): void {
        try {
            $operation();
            self::fail(sprintf('The runtime store self-installed missing table "%s".', $table));
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('S1-DB106', $exception->getMessage());
        }

        self::assertFalse($database->schema()->tableExists($table));
    }
}
