<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\OAuthProvider\OAuthUserProfile;

#[CoversClass(OAuthUserProfile::class)]
final class OAuthUserProfileTest extends TestCase
{
    public function testConstructorSetsAllFields(): void
    {
        $profile = new OAuthUserProfile(
            providerId: 'google_123',
            email: 'user@example.com',
            name: 'Test User',
            avatarUrl: 'https://example.com/avatar.jpg',
        );

        self::assertSame('google_123', $profile->providerId);
        self::assertSame('user@example.com', $profile->email);
        self::assertSame('Test User', $profile->name);
        self::assertSame('https://example.com/avatar.jpg', $profile->avatarUrl);
    }

    public function testAvatarUrlDefaultsToNull(): void
    {
        $profile = new OAuthUserProfile(
            providerId: 'google_123',
            email: 'user@example.com',
            name: 'Test User',
        );

        self::assertNull($profile->avatarUrl);
    }
}
