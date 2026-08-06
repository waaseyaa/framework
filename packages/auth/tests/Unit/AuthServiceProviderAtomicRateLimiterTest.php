<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\AtomicRateLimiterInterface;
use Waaseyaa\Auth\AuthServiceProvider;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Testing\Kernel\KernelServicesFixture;

#[CoversClass(AuthServiceProvider::class)]
final class AuthServiceProviderAtomicRateLimiterTest extends TestCase
{
    #[Test]
    public function it_exposes_one_shared_atomic_limiter_under_both_contracts(): void
    {
        $database = $this->createStub(DatabaseInterface::class);
        $provider = new AuthServiceProvider();
        $provider->setKernelContext('/tmp/test-project', ['environment' => 'testing'], []);
        $provider->setKernelServices(new KernelServicesFixture([
            DatabaseInterface::class => $database,
        ]));
        $provider->register();

        $atomic = $provider->resolve(AtomicRateLimiterInterface::class);

        self::assertInstanceOf(DatabaseRateLimiter::class, $atomic);
        self::assertSame($atomic, $provider->resolve(RateLimiterInterface::class));
    }
}
