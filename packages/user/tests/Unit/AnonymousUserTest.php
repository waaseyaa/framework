<?php

declare(strict_types=1);

namespace Aurora\User\Tests\Unit;

use Aurora\Access\AccountInterface;
use Aurora\User\AnonymousUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnonymousUser::class)]
final class AnonymousUserTest extends TestCase
{
    public function testImplementsAccountInterface(): void
    {
        $anon = new AnonymousUser();
        $this->assertInstanceOf(AccountInterface::class, $anon);
    }

    public function testIdIsAlwaysZero(): void
    {
        $anon = new AnonymousUser();
        $this->assertSame(0, $anon->id());
    }

    public function testIsNeverAuthenticated(): void
    {
        $anon = new AnonymousUser();
        $this->assertFalse($anon->isAuthenticated());
    }

    public function testRolesContainOnlyAnonymous(): void
    {
        $anon = new AnonymousUser();
        $this->assertSame(['anonymous'], $anon->getRoles());
    }

    public function testHasNoPermissionsByDefault(): void
    {
        $anon = new AnonymousUser();
        $this->assertFalse($anon->hasPermission('anything'));
    }

    public function testHasPermissionFromConstructor(): void
    {
        $anon = new AnonymousUser(['access content', 'view published']);

        $this->assertTrue($anon->hasPermission('access content'));
        $this->assertTrue($anon->hasPermission('view published'));
        $this->assertFalse($anon->hasPermission('administer site'));
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(AnonymousUser::class);
        $this->assertTrue($reflection->isFinal());
    }
}
