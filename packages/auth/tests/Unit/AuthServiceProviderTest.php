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
    private function providerWith(array $config): AuthServiceProvider
    {
        $provider = new AuthServiceProvider();
        $provider->setKernelContext('', $config, []);
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                if ($abstract === \Waaseyaa\Database\DatabaseInterface::class) {
                    return DBALDatabase::createSqlite();
                }

                return null;
            }
        });

        return $provider;
    }
}
