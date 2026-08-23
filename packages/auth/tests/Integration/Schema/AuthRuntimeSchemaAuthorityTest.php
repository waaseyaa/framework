<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Integration\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\AuthServiceProvider;
use Waaseyaa\Auth\DatabaseRateLimiter as AuthRateLimiter;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Auth\Token\Bearer\DatabaseBearerTokenStore;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\RateLimit\DatabaseRateLimiter as FoundationRateLimiter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/** Retained-red proof for the migration-less auth runtime schema family. */
final class AuthRuntimeSchemaAuthorityTest extends TestCase
{
    #[Test]
    public function auth_token_operation_refuses_missing_schema_without_installing_it(): void
    {
        $database = DBALDatabase::createSqlite();

        $this->assertMissingSchemaRefused(
            $database,
            'auth_tokens',
            static fn(): mixed => new AuthTokenRepository($database, 'test-secret')->validateToken('missing', 'reset'),
        );
    }

    #[Test]
    public function auth_provider_resolution_refuses_missing_schema_without_installing_it(): void
    {
        $database = DBALDatabase::createSqlite();
        $provider = new AuthServiceProvider();
        $provider->setKernelContext('', [
            'environment' => 'production',
            'auth' => ['token_secret' => 'abcdefghijklmnopqrstuvwxyz012345'],
        ], []);
        $provider->setKernelServices(new class ($database) implements KernelServicesInterface {
            public function __construct(private readonly DBALDatabase $database) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        });
        $provider->register();

        $this->assertMissingSchemaRefused(
            $database,
            'auth_tokens',
            static fn(): mixed => $provider->resolve(AuthTokenRepositoryInterface::class),
        );
    }

    #[Test]
    public function bearer_store_operation_refuses_missing_schema_without_installing_it(): void
    {
        $database = DBALDatabase::createSqlite();

        $this->assertMissingSchemaRefused(
            $database,
            'auth_bearer_token',
            static fn(): mixed => new DatabaseBearerTokenStore($database)->find('mbt_00000000000000ff'),
        );
    }

    #[Test]
    public function auth_rate_limiter_refuses_missing_schema_without_installing_it(): void
    {
        $database = DBALDatabase::createSqlite();

        $this->assertMissingSchemaRefused(
            $database,
            'rate_limits',
            static fn(): mixed => new AuthRateLimiter($database)->attempts('missing'),
        );
    }

    #[Test]
    public function foundation_rate_limiter_refuses_missing_schema_without_installing_it(): void
    {
        $database = DBALDatabase::createSqlite();

        $this->assertMissingSchemaRefused(
            $database,
            'rate_limit_windows',
            static fn(): mixed => new FoundationRateLimiter($database)->attempt('missing', 1, 60),
        );
    }

    /** @param \Closure(): mixed $operation */
    private function assertMissingSchemaRefused(
        DBALDatabase $database,
        string $table,
        \Closure $operation,
    ): void {
        try {
            $operation();
            self::fail(sprintf('The runtime path accepted or self-installed missing table "%s".', $table));
        } catch (\Throwable $exception) {
            $messages = [];
            do {
                $messages[] = $exception->getMessage();
                $exception = $exception->getPrevious();
            } while ($exception !== null);

            self::assertStringContainsString('S1-DB106', implode("\n", $messages));
        }

        self::assertFalse($database->schema()->tableExists($table));
    }
}
