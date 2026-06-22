<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Http\Router\SessionChannel;

#[CoversClass(SessionChannel::class)]
final class SessionChannelTest extends TestCase
{
    #[Test]
    public function token_is_a_stable_non_secret_hash_of_the_session_id(): void
    {
        $token = SessionChannel::tokenForSessionId('raw-session-id-abc');

        self::assertSame($token, SessionChannel::tokenForSessionId('raw-session-id-abc'), 'deterministic');
        self::assertSame(32, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        // Never leaks the raw session id (the auth cookie value).
        self::assertStringNotContainsString('raw-session-id-abc', $token);
    }

    #[Test]
    public function different_sessions_get_different_tokens(): void
    {
        self::assertNotSame(
            SessionChannel::tokenForSessionId('session-a'),
            SessionChannel::tokenForSessionId('session-b'),
        );
    }

    #[Test]
    public function channel_is_the_prefixed_token(): void
    {
        $sid = 'session-a';
        $expected = SessionChannel::PREFIX . SessionChannel::tokenForSessionId($sid);

        self::assertSame($expected, SessionChannel::forSessionId($sid));
        self::assertSame(
            SessionChannel::forSessionId($sid),
            SessionChannel::forToken(SessionChannel::tokenForSessionId($sid)),
        );
    }

    #[Test]
    public function reserved_namespace_is_recognised(): void
    {
        self::assertTrue(SessionChannel::isReserved('session:abc'));
        self::assertTrue(SessionChannel::isReserved(SessionChannel::forSessionId('x')));
        self::assertFalse(SessionChannel::isReserved('admin'));
        self::assertFalse(SessionChannel::isReserved('entity.saved'));
    }
}
