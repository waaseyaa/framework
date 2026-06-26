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
        // Privileged account requesting `admin` — channel is kept and own-session appended.
        $channels = BroadcastRouter::resolveSubscriberChannels(['admin'], 'session:mine', true);

        self::assertSame(['admin', 'session:mine'], $channels);
    }

    #[Test]
    public function client_supplied_private_channels_are_stripped(): void
    {
        // A client trying to subscribe to ANOTHER session's private channel gets
        // it stripped, and only ever receives its OWN (server-derived) one — this
        // is the NFR-001 isolation guarantee. Privileged account so `admin` is kept.
        $channels = BroadcastRouter::resolveSubscriberChannels(
            ['admin', 'session:someone-else', 'session:another'],
            'session:mine',
            true,
        );

        self::assertContains('admin', $channels);
        self::assertContains('session:mine', $channels);
        self::assertNotContains('session:someone-else', $channels);
        self::assertNotContains('session:another', $channels);
    }

    #[Test]
    public function a_client_requesting_only_a_foreign_private_channel_falls_back_to_public_default(): void
    {
        // Privileged account: no public channel survived the strip, so the `admin`
        // default is restored; the foreign private channel is never honoured.
        $channels = BroadcastRouter::resolveSubscriberChannels(['session:someone-else'], 'session:mine', true);

        self::assertSame(['admin', 'session:mine'], $channels);
    }

    #[Test]
    public function empty_request_defaults_to_admin_plus_own_session(): void
    {
        // Privileged account: empty request defaults to admin + own session.
        self::assertSame(['admin', 'session:mine'], BroadcastRouter::resolveSubscriberChannels([], 'session:mine', true));
    }

    #[Test]
    public function no_session_yields_public_channels_only(): void
    {
        // Privileged account, no session: only the requested public channel returned.
        self::assertSame(['admin'], BroadcastRouter::resolveSubscriberChannels(['admin'], null, true));
        // Privileged account, no session, empty request: defaults to admin.
        self::assertSame(['admin'], BroadcastRouter::resolveSubscriberChannels([], null, true));
    }

    #[Test]
    public function duplicate_public_channels_are_collapsed(): void
    {
        // Privileged account: duplicates collapsed, non-privileged `events` kept alongside `admin`.
        self::assertSame(
            ['admin', 'events', 'session:mine'],
            BroadcastRouter::resolveSubscriberChannels(['admin', 'events', 'admin'], 'session:mine', true),
        );
    }

    #[Test]
    public function non_privileged_account_cannot_access_admin_channel(): void
    {
        // Non-privileged (anonymous/non-admin) account: `admin` is stripped and
        // NOT defaulted back, only own session is returned.
        $channels = BroadcastRouter::resolveSubscriberChannels(['admin'], 'session:mine', false);
        self::assertSame(['session:mine'], $channels);

        // Without a session either, the result is empty.
        $channels = BroadcastRouter::resolveSubscriberChannels(['admin'], null, false);
        self::assertSame([], $channels);
    }
}
