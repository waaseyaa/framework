<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests;

use PHPUnit\Framework\TestCase;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\ProviderRegistry;

final class ProviderRegistryTest extends TestCase
{
    public function testRegisterAndGet(): void
    {
        $provider = $this->createStub(OAuthProviderInterface::class);
        $registry = new ProviderRegistry();
        $registry->register('google', $provider);
        self::assertSame($provider, $registry->get('google'));
    }

    public function testHasReturnsTrueForRegistered(): void
    {
        $provider = $this->createStub(OAuthProviderInterface::class);
        $registry = new ProviderRegistry();
        $registry->register('google', $provider);
        self::assertTrue($registry->has('google'));
        self::assertFalse($registry->has('github'));
    }

    public function testGetThrowsForUnregistered(): void
    {
        $registry = new ProviderRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("OAuth provider 'unknown' is not registered");
        $registry->get('unknown');
    }

    public function testAllReturnsAllProviders(): void
    {
        $google = $this->createStub(OAuthProviderInterface::class);
        $github = $this->createStub(OAuthProviderInterface::class);
        $registry = new ProviderRegistry();
        $registry->register('google', $google);
        $registry->register('github', $github);
        self::assertCount(2, $registry->all());
        self::assertSame(['google' => $google, 'github' => $github], $registry->all());
    }
}
