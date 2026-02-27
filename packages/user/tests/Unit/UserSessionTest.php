<?php

declare(strict_types=1);

namespace Aurora\User\Tests\Unit;

use Aurora\Access\AccountInterface;
use Aurora\User\AnonymousUser;
use Aurora\User\User;
use Aurora\User\UserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserSession::class)]
final class UserSessionTest extends TestCase
{
    public function testDefaultsToAnonymousUser(): void
    {
        $session = new UserSession();
        $account = $session->getAccount();

        $this->assertInstanceOf(AnonymousUser::class, $account);
        $this->assertSame(0, $account->id());
    }

    public function testDefaultSessionIsNotAuthenticated(): void
    {
        $session = new UserSession();
        $this->assertFalse($session->isAuthenticated());
    }

    public function testConstructWithAuthenticatedUser(): void
    {
        $user = new User(['uid' => 1, 'name' => 'admin']);
        $session = new UserSession($user);

        $this->assertSame($user, $session->getAccount());
        $this->assertTrue($session->isAuthenticated());
    }

    public function testSetAccount(): void
    {
        $session = new UserSession();
        $this->assertFalse($session->isAuthenticated());

        $user = new User(['uid' => 7, 'name' => 'bob']);
        $session->setAccount($user);

        $this->assertSame($user, $session->getAccount());
        $this->assertTrue($session->isAuthenticated());
    }

    public function testSetAccountToAnonymous(): void
    {
        $user = new User(['uid' => 1]);
        $session = new UserSession($user);
        $this->assertTrue($session->isAuthenticated());

        $session->setAccount(new AnonymousUser());
        $this->assertFalse($session->isAuthenticated());
    }

    public function testAcceptsAnyAccountInterface(): void
    {
        $mock = $this->createMock(AccountInterface::class);
        $mock->method('isAuthenticated')->willReturn(true);
        $mock->method('id')->willReturn(99);

        $session = new UserSession($mock);
        $this->assertTrue($session->isAuthenticated());
        $this->assertSame($mock, $session->getAccount());
    }

    public function testConstructWithNull(): void
    {
        $session = new UserSession(null);
        $this->assertInstanceOf(AnonymousUser::class, $session->getAccount());
        $this->assertFalse($session->isAuthenticated());
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(UserSession::class);
        $this->assertTrue($reflection->isFinal());
    }
}
