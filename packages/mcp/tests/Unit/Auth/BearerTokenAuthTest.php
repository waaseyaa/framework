<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Auth;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BearerTokenAuth::class)]
final class BearerTokenAuthTest extends TestCase
{
    private AuthorizationPrincipalInterface $account;
    private BearerTokenAuth $auth;

    protected function setUp(): void
    {
        $this->account = $this->createMock(AuthorizationPrincipalInterface::class);
        $this->account->method('id')->willReturn(1);

        $this->auth = new BearerTokenAuth([
            'valid-token-123' => $this->account,
        ]);
    }

    #[Test]
    public function validTokenReturnsAccount(): void
    {
        $result = $this->auth->authenticate('Bearer valid-token-123');
        $this->assertSame($this->account, $result);
    }

    #[Test]
    public function invalidTokenReturnsNull(): void
    {
        $result = $this->auth->authenticate('Bearer wrong-token');
        $this->assertNull($result);
    }

    #[Test]
    public function missingHeaderReturnsNull(): void
    {
        $result = $this->auth->authenticate(null);
        $this->assertNull($result);
    }

    #[Test]
    public function emptyHeaderReturnsNull(): void
    {
        $result = $this->auth->authenticate('');
        $this->assertNull($result);
    }

    #[Test]
    public function malformedHeaderWithoutBearerPrefixReturnsNull(): void
    {
        $result = $this->auth->authenticate('Basic valid-token-123');
        $this->assertNull($result);
    }

    #[Test]
    public function bearerPrefixIsCaseInsensitive(): void
    {
        $result = $this->auth->authenticate('bearer valid-token-123');
        $this->assertSame($this->account, $result);
    }

    #[Test]
    public function tokenWithNoTokensConfiguredReturnsNull(): void
    {
        $emptyAuth = new BearerTokenAuth([]);
        $this->assertNull($emptyAuth->authenticate('Bearer any-token'));
    }
}
