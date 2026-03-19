<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\AuthManager;
use Waaseyaa\User\User;

#[CoversClass(AuthManager::class)]
final class AuthManagerTest extends TestCase
{
    private AuthManager $auth;

    protected function setUp(): void
    {
        $this->auth = new AuthManager();
    }

    public function testAuthenticateReturnsUserOnValidCredentials(): void
    {
        $user = $this->createActiveUser('alice@test.com', 'secret123');

        $result = $this->auth->authenticate($user, 'secret123');

        $this->assertTrue($result);
    }

    public function testAuthenticateReturnsFalseOnWrongPassword(): void
    {
        $user = $this->createActiveUser('alice@test.com', 'secret123');

        $result = $this->auth->authenticate($user, 'wrongpassword');

        $this->assertFalse($result);
    }

    public function testAuthenticateReturnsFalseForInactiveUser(): void
    {
        $user = $this->createUser('blocked@test.com', 'secret123', active: false);

        $result = $this->auth->authenticate($user, 'secret123');

        $this->assertFalse($result);
    }

    public function testLoginSetsSessionUid(): void
    {
        $_SESSION = [];
        $user = $this->createActiveUser('alice@test.com', 'secret123');

        $this->auth->login($user);

        $this->assertSame($user->id(), $_SESSION['waaseyaa_uid']);
    }

    public function testLoginRegeneratesSessionId(): void
    {
        $_SESSION = ['waaseyaa_uid' => 'old-id', 'other' => 'data'];
        $user = $this->createActiveUser('alice@test.com', 'secret123');

        $this->auth->login($user);

        $this->assertSame($user->id(), $_SESSION['waaseyaa_uid']);
    }

    public function testLogoutClearsSession(): void
    {
        $_SESSION = ['waaseyaa_uid' => '123', 'other' => 'data'];

        $this->auth->logout();

        $this->assertArrayNotHasKey('waaseyaa_uid', $_SESSION);
    }

    public function testIsAuthenticatedReturnsTrueWhenSessionHasUid(): void
    {
        $_SESSION = ['waaseyaa_uid' => '123'];

        $this->assertTrue($this->auth->isAuthenticated());
    }

    public function testIsAuthenticatedReturnsFalseWhenNoSession(): void
    {
        $_SESSION = [];

        $this->assertFalse($this->auth->isAuthenticated());
    }

    private function createActiveUser(string $email, string $password): User
    {
        return $this->createUser($email, $password, active: true);
    }

    private function createUser(string $email, string $password, bool $active): User
    {
        $user = new User([
            'uid' => 'user-' . bin2hex(random_bytes(4)),
            'name' => explode('@', $email)[0],
            'mail' => $email,
            'pass' => password_hash($password, PASSWORD_BCRYPT),
            'status' => $active ? 1 : 0,
            'roles' => ['authenticated'],
            'created' => time(),
        ], 'user');

        return $user;
    }
}
