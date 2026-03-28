<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\OAuthProvider\OAuthToken;

#[CoversClass(OAuthToken::class)]
final class OAuthTokenTest extends TestCase
{
    public function testConstructorSetsAllFields(): void
    {
        $expiresAt = new \DateTimeImmutable('+1 hour');
        $token = new OAuthToken(
            accessToken: 'access_123',
            refreshToken: 'refresh_456',
            expiresAt: $expiresAt,
            scopes: ['read', 'write'],
            tokenType: 'Bearer',
        );

        self::assertSame('access_123', $token->accessToken);
        self::assertSame('refresh_456', $token->refreshToken);
        self::assertSame($expiresAt, $token->expiresAt);
        self::assertSame(['read', 'write'], $token->scopes);
        self::assertSame('Bearer', $token->tokenType);
    }

    public function testTokenTypeDefaultsToBearer(): void
    {
        $token = new OAuthToken(
            accessToken: 'access_123',
            refreshToken: null,
            expiresAt: null,
            scopes: [],
        );

        self::assertSame('Bearer', $token->tokenType);
    }

    public function testIsExpiredReturnsFalseWhenExpiresAtIsNull(): void
    {
        $token = new OAuthToken(
            accessToken: 'access_123',
            refreshToken: null,
            expiresAt: null,
            scopes: [],
        );

        self::assertFalse($token->isExpired());
    }

    public function testIsExpiredReturnsTrueForPastExpiry(): void
    {
        $token = new OAuthToken(
            accessToken: 'access_123',
            refreshToken: null,
            expiresAt: new \DateTimeImmutable('-1 hour'),
            scopes: [],
        );

        self::assertTrue($token->isExpired());
    }

    public function testIsExpiredReturnsFalseForFutureExpiry(): void
    {
        $token = new OAuthToken(
            accessToken: 'access_123',
            refreshToken: null,
            expiresAt: new \DateTimeImmutable('+1 hour'),
            scopes: [],
        );

        self::assertFalse($token->isExpired());
    }
}
