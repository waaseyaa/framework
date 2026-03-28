<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;
use Waaseyaa\OAuthProvider\ProviderRegistry;

#[CoversClass(ProviderRegistry::class)]
final class ProviderRegistryTest extends TestCase
{
    public function testRegisterAndGet(): void
    {
        $registry = new ProviderRegistry();
        $provider = $this->createStub(OAuthProviderInterface::class);

        $registry->register('google', $provider);

        self::assertSame($provider, $registry->get('google'));
    }

    public function testGetThrowsForUnregisteredProvider(): void
    {
        $registry = new ProviderRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth provider "github" is not registered.');

        $registry->get('github');
    }

    public function testHasReturnsTrueForRegisteredProvider(): void
    {
        $registry = new ProviderRegistry();
        $provider = $this->createStub(OAuthProviderInterface::class);

        $registry->register('google', $provider);

        self::assertTrue($registry->has('google'));
    }

    public function testHasReturnsFalseForUnregisteredProvider(): void
    {
        $registry = new ProviderRegistry();

        self::assertFalse($registry->has('google'));
    }

    public function testAllReturnsAllProviders(): void
    {
        $registry = new ProviderRegistry();
        $google = $this->createStub(OAuthProviderInterface::class);
        $github = $this->createStub(OAuthProviderInterface::class);

        $registry->register('google', $google);
        $registry->register('github', $github);

        $all = $registry->all();

        self::assertCount(2, $all);
        self::assertSame($google, $all['google']);
        self::assertSame($github, $all['github']);
    }
}
