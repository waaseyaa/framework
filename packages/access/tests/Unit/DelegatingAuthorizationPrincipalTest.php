<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\DelegatingAuthorizationPrincipal;

final class DelegatingAuthorizationPrincipalTest extends TestCase
{
    #[Test]
    public function it_preserves_account_authorization_behavior_and_explicit_claims(): void
    {
        $account = new class implements AccountInterface {
            public function id(): int|string { return 42; }
            public function hasPermission(string $permission): bool { return $permission === 'site.publish'; }
            public function getRoles(): array { return ['editor']; }
            public function isAuthenticated(): bool { return true; }
        };

        $principal = new DelegatingAuthorizationPrincipal($account, 'claims-v7', 'tenant-a', 'community-b');

        self::assertSame(42, $principal->id());
        self::assertTrue($principal->hasPermission('site.publish'));
        self::assertFalse($principal->hasPermission('site.delete'));
        self::assertSame(['editor'], $principal->getRoles());
        self::assertSame('claims-v7', $principal->claimsGeneration());
        self::assertSame('tenant-a', $principal->tenantId());
        self::assertSame('community-b', $principal->communityId());
    }

    #[Test]
    public function it_refuses_an_empty_claims_generation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DelegatingAuthorizationPrincipal(new class implements AccountInterface {
            public function id(): int|string { return 1; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return true; }
        }, '');
    }
}
