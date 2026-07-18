<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\Http\AuthController;
use Waaseyaa\User\User;

#[CoversClass(AuthController::class)]
final class AuthControllerTest extends TestCase
{
    private function controller(): AuthController
    {
        return new AuthController(new UserInternalFieldReaderFixture());
    }

    #[Test]
    public function meReturnsUserDataForAuthenticatedAccount(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'mail' => 'alice@example.com', 'roles' => ['editor']]);

        $result = $this->controller()->me($user);

        self::assertSame(200, $result['statusCode']);
        self::assertSame(5, $result['data']['id']);
        self::assertSame('alice', $result['data']['name']);
        self::assertSame('alice@example.com', $result['data']['email']);
        self::assertSame(['editor'], $result['data']['roles']);
    }

    #[Test]
    public function meReturns401ForAnonymousAccount(): void
    {
        $result = $this->controller()->me(new AnonymousUser());

        self::assertSame(401, $result['statusCode']);
    }

    #[Test]
    public function meUsesTheInternalReaderRoles(): void
    {
        $user = new User(['uid' => 7, 'name' => 'carol', 'roles' => ['admin', 'editor']]);

        $result = $this->controller()->me($user);

        self::assertSame(['admin', 'editor'], $result['data']['roles']);
    }
}
