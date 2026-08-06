<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Testing\Factory\AuthorizationPrincipalFactory;

#[CoversClass(AuthorizationPrincipalFactory::class)]
final class AuthorizationPrincipalFactoryTest extends TestCase
{
    #[Test]
    public function it_builds_real_immutable_authorization_principals(): void
    {
        $principal = AuthorizationPrincipalFactory::authenticated(
            id: 42,
            roles: ['editor'],
            permissions: ['edit article'],
            tenantId: 'tenant-a',
            communityId: 'community-b',
        );

        self::assertSame(42, $principal->id());
        self::assertTrue($principal->isAuthenticated());
        self::assertSame(['editor'], $principal->getRoles());
        self::assertTrue($principal->hasPermission('edit article'));
        self::assertFalse($principal->hasPermission('administer site'));
        self::assertSame('test-claims-v1', $principal->claimsGeneration());
        self::assertSame('tenant-a', $principal->tenantId());
        self::assertSame('community-b', $principal->communityId());
    }

    #[Test]
    public function anonymous_principals_are_explicit_and_permissionless(): void
    {
        $principal = AuthorizationPrincipalFactory::anonymous();

        self::assertSame(0, $principal->id());
        self::assertFalse($principal->isAuthenticated());
        self::assertFalse($principal->hasPermission('anything'));
    }
}
