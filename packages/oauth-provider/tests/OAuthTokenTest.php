<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests;

use PHPUnit\Framework\TestCase;
use Waaseyaa\OAuthProvider\OAuthToken;

final class OAuthTokenTest extends TestCase
{
    public function testCreateWithAllFields(): void
    {
        $expiresAt = new \DateTimeImmutable('+3600 seconds');
        $token = new OAuthToken(
            accessToken: 'ya29.access',
            refreshToken: 'refresh-123',
            expiresAt: $expiresAt,
            scopes: ['email', 'profile'],
            tokenType: 'Bearer',
        );

        self::assertSame('ya29.access', $token->accessToken);
        self::assertSame('refresh-123', $token->refreshToken);
        self::assertSame($expiresAt, $token->expiresAt);
        self::assertSame(['email', 'profile'], $token->scopes);
        self::assertSame('Bearer', $token->tokenType);
    }

    public function testCreateWithNullableFields(): void
    {
        $token = new OAuthToken(
            accessToken: 'gho_abc123',
            refreshToken: null,
            expiresAt: null,
            scopes: ['repo'],
            tokenType: 'bearer',
        );

        self::assertSame('gho_abc123', $token->accessToken);
        self::assertNull($token->refreshToken);
        self::assertNull($token->expiresAt);
    }
}
