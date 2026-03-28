<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests;

use PHPUnit\Framework\TestCase;
use Waaseyaa\OAuthProvider\OAuthUserProfile;

final class OAuthUserProfileTest extends TestCase
{
    public function testCreateWithAllFields(): void
    {
        $profile = new OAuthUserProfile(
            providerId: '12345',
            email: 'user@example.com',
            name: 'Jane Doe',
            avatarUrl: 'https://example.com/avatar.jpg',
        );

        self::assertSame('12345', $profile->providerId);
        self::assertSame('user@example.com', $profile->email);
        self::assertSame('Jane Doe', $profile->name);
        self::assertSame('https://example.com/avatar.jpg', $profile->avatarUrl);
    }

    public function testCreateWithNullAvatar(): void
    {
        $profile = new OAuthUserProfile(
            providerId: '67890',
            email: 'user@example.com',
            name: 'Jane',
        );

        self::assertNull($profile->avatarUrl);
    }
}
