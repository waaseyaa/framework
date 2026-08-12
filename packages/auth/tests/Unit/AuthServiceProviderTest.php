<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\AuthServiceProvider;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Auth\Tests\Support\AuthSchema;

#[CoversClass(AuthServiceProvider::class)]
final class AuthServiceProviderTest extends TestCase
{
    #[Test]
    public function missing_token_secret_fails_loudly_in_production(): void
    {
        $provider = $this->providerWith(['environment' => 'production']);
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/change-me/');

        $provider->resolve(AuthTokenRepositoryInterface::class);
    }

    #[Test]
    public function change_me_literal_is_rejected_in_production(): void
    {
        $provider = $this->providerWith(['environment' => 'production', 'app_secret' => 'change-me']);
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/change-me/');

        $provider->resolve(AuthTokenRepositoryInterface::class);
    }

    #[Test]
    public function configured_token_secret_resolves(): void
    {
        $provider = $this->providerWith([
            'environment' => 'production',
            'auth' => ['token_secret' => 'a-real-secret-value'],
        ]);
        $provider->register();

        $repo = $provider->resolve(AuthTokenRepositoryInterface::class);

        $this->assertInstanceOf(AuthTokenRepository::class, $repo);
    }

    #[Test]
    public function non_production_without_secret_synthesises_ephemeral_secret(): void
    {
        // Boot-safety guarantee: a dev/test app with no configured secret must still
        // boot (with a random ephemeral secret) rather than throwing — this is what
        // keeps the skeleton (APP_ENV=local) and route-wiring integration tests green.
        $provider = $this->providerWith(['environment' => 'local']);
        $provider->register();

        $repo = $provider->resolve(AuthTokenRepositoryInterface::class);

        $this->assertInstanceOf(AuthTokenRepository::class, $repo);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    public function bearer_token_store_binding_resolves_the_database_store(): void
    {
        $provider = $this->providerWith([]);
        $provider->register();

        $store = $provider->resolve(\Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface::class);

        $this->assertInstanceOf(\Waaseyaa\Auth\Token\Bearer\DatabaseBearerTokenStore::class, $store);
    }

    #[Test]
    public function the_domain_provider_does_not_own_console_commands(): void
    {
        $provider = $this->providerWith([]);
        $provider->register();

        $this->assertNotInstanceOf(
            \Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface::class,
            $provider,
        );
    }

    private function providerWith(array $config): AuthServiceProvider
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $provider = new AuthServiceProvider();
        $provider->setKernelContext('', $config, []);
        $provider->setKernelServices(new class($database) implements KernelServicesInterface {
            public function __construct(private readonly DBALDatabase $database) {}

            public function get(string $abstract): ?object
            {
                if ($abstract === \Waaseyaa\Database\DatabaseInterface::class) {
                    return $this->database;
                }

                return null;
            }
        });

        return $provider;
    }
}
