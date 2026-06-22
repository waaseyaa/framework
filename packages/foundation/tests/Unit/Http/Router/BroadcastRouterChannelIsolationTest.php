<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Http\Router\BroadcastRouter;

#[CoversClass(BroadcastRouter::class)]
final class BroadcastRouterChannelIsolationTest extends TestCase
{
    #[Test]
    public function own_session_channel_is_appended_to_public_channels(): void
    {
        $channels = BroadcastRouter::resolveSubscriberChannels(['admin'], 'session:mine');

        self::assertSame(['admin', 'session:mine'], $channels);
    }

    #[Test]
    public function client_supplied_private_channels_are_stripped(): void
    {
        // A client trying to subscribe to ANOTHER session's private channel gets
        // it stripped, and only ever receives its OWN (server-derived) one — this
        // is the NFR-001 isolation guarantee.
        $channels = BroadcastRouter::resolveSubscriberChannels(
            ['admin', 'session:someone-else', 'session:another'],
            'session:mine',
        );

        self::assertContains('admin', $channels);
        self::assertContains('session:mine', $channels);
        self::assertNotContains('session:someone-else', $channels);
        self::assertNotContains('session:another', $channels);
    }

    #[Test]
    public function a_client_requesting_only_a_foreign_private_channel_falls_back_to_public_default(): void
    {
        $channels = BroadcastRouter::resolveSubscriberChannels(['session:someone-else'], 'session:mine');

        // No public channel survived the strip, so the 'admin' default is restored;
        // the foreign private channel is never honoured.
        self::assertSame(['admin', 'session:mine'], $channels);
    }

    #[Test]
    public function empty_request_defaults_to_admin_plus_own_session(): void
    {
        self::assertSame(['admin', 'session:mine'], BroadcastRouter::resolveSubscriberChannels([], 'session:mine'));
    }

    #[Test]
    public function no_session_yields_public_channels_only(): void
    {
        self::assertSame(['admin'], BroadcastRouter::resolveSubscriberChannels(['admin'], null));
        self::assertSame(['admin'], BroadcastRouter::resolveSubscriberChannels([], null));
    }

    #[Test]
    public function duplicate_public_channels_are_collapsed(): void
    {
        self::assertSame(
            ['admin', 'events', 'session:mine'],
            BroadcastRouter::resolveSubscriberChannels(['admin', 'events', 'admin'], 'session:mine'),
        );
    }
}
