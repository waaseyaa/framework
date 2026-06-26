<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Http\Router\BroadcastRouter;

#[CoversClass(BroadcastRouter::class)]
final class BroadcastRouterTest extends TestCase
{
    #[Test]
    public function supports_broadcast_controller(): void
    {
        $router = new BroadcastRouter();
        $request = Request::create('/api/broadcast');
        $request->attributes->set('_controller', 'broadcast');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $router = new BroadcastRouter();
        $request = Request::create('/api/openapi');
        $request->attributes->set('_controller', 'openapi');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function resolve_initial_cursor_uses_high_water_mark_when_no_last_event_id(): void
    {
        $request = Request::create('/api/broadcast?channels=admin');

        self::assertSame(42, BroadcastRouter::resolveInitialCursor($request, 42));
        self::assertSame(0, BroadcastRouter::resolveInitialCursor($request, 0));
    }

    #[Test]
    public function resolve_initial_cursor_resumes_from_last_event_id_header(): void
    {
        $request = Request::create('/api/broadcast?channels=admin');
        $request->headers->set('Last-Event-ID', '17');

        // Even though storage's high-water mark is 99, we resume at 17.
        self::assertSame(17, BroadcastRouter::resolveInitialCursor($request, 99));
    }

    #[Test]
    public function resolve_initial_cursor_rejects_non_numeric_last_event_id(): void
    {
        $request = Request::create('/api/broadcast?channels=admin');
        $request->headers->set('Last-Event-ID', 'not-a-number');

        self::assertSame(42, BroadcastRouter::resolveInitialCursor($request, 42));
    }

    #[Test]
    public function handle_returns_streamed_response(): void
    {
        $router = new BroadcastRouter();

        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $broadcastStorage = new \Waaseyaa\Api\Controller\BroadcastStorage($db);

        $account = $this->createStub(\Waaseyaa\Access\AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);
        $account->method('hasPermission')->willReturn(false);
        $request = Request::create('/api/broadcast?channels=admin');
        $request->attributes->set('_controller', 'broadcast');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', $broadcastStorage);
        $request->attributes->set('_parsed_body', null);
        $request->attributes->set(
            '_waaseyaa_context',
            \Waaseyaa\Foundation\Http\Router\WaaseyaaContext::fromRequest($request),
        );

        $response = $router->handle($request);

        self::assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $response);
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function stream_should_continue_only_while_connected_and_within_budget(): void
    {
        // Connected and within budget → keep streaming.
        self::assertTrue(BroadcastRouter::streamShouldContinue(0, 0, 30));
        self::assertTrue(BroadcastRouter::streamShouldContinue(0, 29, 30));
        // Client disconnected (connection_aborted() !== 0) → stop, release worker.
        self::assertFalse(BroadcastRouter::streamShouldContinue(1, 0, 30));
        // Time budget elapsed → stop; the client EventSource reconnects.
        self::assertFalse(BroadcastRouter::streamShouldContinue(0, 30, 30));
        self::assertFalse(BroadcastRouter::streamShouldContinue(0, 31, 30));
    }

    /**
     * Run the streamed-response callback to completion, capturing emitted bytes.
     * Nested output buffers so the handler's ob_flush()/flush() pair lands in a
     * buffer we can read instead of going to the SAPI.
     */
    private function runStream(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();      // capture sink
        ob_start();      // where echo writes; handler's ob_flush() pushes into the sink
        ($response->getCallback())();
        ob_end_flush();  // merge inner remainder into the sink
        return (string) ob_get_clean();
    }

    private function broadcastRequest(\Waaseyaa\Api\Controller\BroadcastStorage $storage): Request
    {
        // Use an admin-role account so the existing streaming tests continue to
        // exercise the `admin` channel after the per-channel ACL was introduced.
        $account = $this->createStub(\Waaseyaa\Access\AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);
        $account->method('hasPermission')->willReturn(false);
        $request = Request::create('/api/broadcast?channels=admin');
        $request->attributes->set('_controller', 'broadcast');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', $storage);
        $request->attributes->set('_parsed_body', null);
        $request->attributes->set(
            '_waaseyaa_context',
            \Waaseyaa\Foundation\Http\Router\WaaseyaaContext::fromRequest($request),
        );

        return $request;
    }

    #[Test]
    public function stream_loop_exits_when_time_budget_elapses(): void
    {
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $storage = new \Waaseyaa\Api\Controller\BroadcastStorage($db);

        // Monotonic fake clock so the per-connection budget is reached
        // deterministically; abort always 0 (client stays connected).
        $now = 0;
        $clock = function () use (&$now): int {
            return $now++;
        };

        $router = new BroadcastRouter(
            maxDurationSec: 4,
            keepaliveIntervalSec: 1,
            pollIntervalUs: 0,
            clock: $clock,
            abortSignal: static fn(): int => 0,
        );

        $out = $this->runStream($router->handle($this->broadcastRequest($storage)));

        // It returned (no hang) and announced the stream, and emitted a keepalive
        // at the short cadence — the disconnect probe that frees the worker.
        self::assertStringContainsString('event: connected', $out);
        self::assertStringContainsString(': keepalive', $out);
    }

    #[Test]
    public function stream_loop_exits_promptly_on_client_disconnect(): void
    {
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $storage = new \Waaseyaa\Api\Controller\BroadcastStorage($db);

        // abort returns 0 once, then 1 — simulating the client navigating away.
        $calls = 0;
        $abort = function () use (&$calls): int {
            return $calls++ === 0 ? 0 : 1;
        };

        $router = new BroadcastRouter(
            maxDurationSec: 30,
            keepaliveIntervalSec: 30,
            pollIntervalUs: 0,
            abortSignal: $abort,
        );

        // Must return without reaching the 30s budget — disconnect alone ends it.
        $out = $this->runStream($router->handle($this->broadcastRequest($storage)));
        self::assertStringContainsString('event: connected', $out);
    }

    #[Test]
    public function stream_delivers_pushed_events_so_realtime_still_works(): void
    {
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $storage = new \Waaseyaa\Api\Controller\BroadcastStorage($db);

        $now = 0;
        $clock = function () use (&$now): int {
            return $now++;
        };
        $router = new BroadcastRouter(
            maxDurationSec: 4,
            keepaliveIntervalSec: 1,
            pollIntervalUs: 0,
            clock: $clock,
            abortSignal: static fn(): int => 0,
        );

        // Connect first (cursor = current high-water mark = 0), then publish.
        $request = $this->broadcastRequest($storage);
        $response = $router->handle($request);
        $storage->push('admin', 'entity.saved', ['entityType' => 'story', 'id' => 2]);

        $out = $this->runStream($response);

        self::assertStringContainsString('event: entity.saved', $out);
        self::assertStringContainsString('"entityType":"story"', $out);
    }

    #[Test]
    public function stream_closes_the_php_session_to_unblock_concurrent_same_session_requests(): void
    {
        if (session_status() === \PHP_SESSION_DISABLED) {
            self::markTestSkipped('PHP sessions are disabled in this SAPI.');
        }

        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $storage = new \Waaseyaa\Api\Controller\BroadcastStorage($db);

        // Simulate SessionMiddleware having opened the native session. Its file
        // lock would otherwise serialize every concurrent same-PHPSESSID request
        // (the SPA's reloads, /api/* fetches, a second tab) behind this stream
        // for its whole lifetime — the 15-25s admin blank under reloads/tabs.
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            @session_start();
        }
        self::assertSame(\PHP_SESSION_ACTIVE, session_status());

        $now = 0;
        $clock = function () use (&$now): int {
            return $now++;
        };
        $router = new BroadcastRouter(
            maxDurationSec: 1,
            keepaliveIntervalSec: 1,
            pollIntervalUs: 0,
            clock: $clock,
            abortSignal: static fn(): int => 0,
        );

        $this->runStream($router->handle($this->broadcastRequest($storage)));

        // The stream must have released the session lock (closed the session)
        // before entering its loop, so concurrent same-session requests proceed.
        self::assertSame(\PHP_SESSION_NONE, session_status());
    }

    #[Test]
    public function stream_clears_ignore_user_abort_so_disconnects_surface(): void
    {
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $storage = new \Waaseyaa\Api\Controller\BroadcastStorage($db);

        // Simulate the FrankenPHP / php-fpm request bootstrap, which enables
        // ignore_user_abort(true). With that left on, a failed write to a
        // navigated-away client never flips connection_aborted(), so the stream
        // would pin the worker until its time budget — the bug behind the admin
        // SPA blank-screen stall under reloads / multiple tabs.
        $previous = ignore_user_abort(true);
        try {
            $now = 0;
            $clock = function () use (&$now): int {
                return $now++;
            };
            $router = new BroadcastRouter(
                maxDurationSec: 1,
                keepaliveIntervalSec: 1,
                pollIntervalUs: 0,
                clock: $clock,
                abortSignal: static fn(): int => 0,
            );

            $this->runStream($router->handle($this->broadcastRequest($storage)));

            // The stream must have cleared the flag for its own lifetime so a
            // dead-socket write can surface as an abort and release the worker.
            self::assertSame(0, ignore_user_abort());
        } finally {
            ignore_user_abort((bool) $previous);
        }
    }

    // --- per-account concurrent-stream cap (#1704 residual 503) ---

    #[Test]
    public function handle_refuses_with_503_and_retry_after_when_account_at_concurrent_cap(): void
    {
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $storage = new \Waaseyaa\Api\Controller\BroadcastStorage($db);

        // Pre-populate subscribers.json with `cap` active streams for account 0
        // (the stub account's id()), as if a reload storm already opened them.
        $now = microtime(true);
        $path = sys_get_temp_dir() . '/waaseyaa-subs-' . uniqid('', true) . '.json';
        file_put_contents($path, (string) json_encode([
            ['accountId' => 0, 'accountLabel' => null, 'channels' => ['admin'], 'connectedSince' => $now, 'lastHeartbeat' => $now, 'connectionId' => 'c1'],
            ['accountId' => 0, 'accountLabel' => null, 'channels' => ['admin'], 'connectedSince' => $now, 'lastHeartbeat' => $now, 'connectionId' => 'c2'],
        ]));

        try {
            $router = new BroadcastRouter(
                subscribersJsonPath: $path,
                maxConcurrentStreams: 2,
                retryAfterSec: 7,
            );

            $response = $router->handle($this->broadcastRequest($storage));

            self::assertNotInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $response);
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('7', $response->headers->get('Retry-After'));
            self::assertStringContainsString('too_many_concurrent_streams', (string) $response->getContent());
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function handle_streams_when_below_concurrent_cap(): void
    {
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $storage = new \Waaseyaa\Api\Controller\BroadcastStorage($db);

        $now = microtime(true);
        $path = sys_get_temp_dir() . '/waaseyaa-subs-' . uniqid('', true) . '.json';
        file_put_contents($path, (string) json_encode([
            ['accountId' => 0, 'accountLabel' => null, 'channels' => ['admin'], 'connectedSince' => $now, 'lastHeartbeat' => $now, 'connectionId' => 'c1'],
        ]));

        try {
            $router = new BroadcastRouter(
                subscribersJsonPath: $path,
                maxConcurrentStreams: 2,
                // Keep the loop from actually running long if the callback is invoked elsewhere.
                maxDurationSec: 1,
                pollIntervalUs: 0,
                abortSignal: static fn(): int => 1,
            );

            $response = $router->handle($this->broadcastRequest($storage));

            // One active stream < cap of 2 → admitted as a normal SSE stream.
            self::assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $response);
            self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function count_active_streams_filters_by_account_and_excludes_stale_rows(): void
    {
        $now = 1_000.0;
        $rows = [
            ['accountId' => 7, 'lastHeartbeat' => $now - 1.0, 'connectionId' => 'a'],   // account 7, fresh
            ['accountId' => 7, 'connectedSince' => $now - 2.0, 'connectionId' => 'b'],  // account 7, fresh (no heartbeat → connectedSince)
            ['accountId' => 7, 'lastHeartbeat' => $now - 999.0, 'connectionId' => 'c'], // account 7, STALE (excluded)
            ['accountId' => 3, 'lastHeartbeat' => $now, 'connectionId' => 'd'],         // other account (excluded)
            'not-an-array',                                                              // malformed (skipped)
        ];

        // staleAfter = 30s: rows c (999s old) excluded; a and b counted.
        self::assertSame(2, BroadcastRouter::countActiveStreamsForAccount($rows, 7, $now, 30.0));
        // Account 3 has one fresh row.
        self::assertSame(1, BroadcastRouter::countActiveStreamsForAccount($rows, 3, $now, 30.0));
        // An account with no rows.
        self::assertSame(0, BroadcastRouter::countActiveStreamsForAccount($rows, 99, $now, 30.0));
        // staleAfter = 0 disables the staleness filter → all account-7 rows count.
        self::assertSame(3, BroadcastRouter::countActiveStreamsForAccount($rows, 7, $now, 0.0));
    }

    // --- per-channel ACL: resolveSubscriberChannels() ---

    #[Test]
    public function resolve_subscriber_channels_strips_admin_for_non_privileged_account(): void
    {
        // An anonymous/non-admin account requesting `admin` must receive an empty
        // channel list (not defaulted back onto the privileged channel).
        self::assertSame([], BroadcastRouter::resolveSubscriberChannels(['admin'], null, false));
    }

    #[Test]
    public function resolve_subscriber_channels_keeps_admin_for_privileged_account(): void
    {
        self::assertSame(['admin'], BroadcastRouter::resolveSubscriberChannels(['admin'], null, true));
    }

    #[Test]
    public function resolve_subscriber_channels_default_admin_only_for_authorized_account(): void
    {
        // Non-admin: empty requested → empty result (NOT defaulted to admin).
        self::assertSame([], BroadcastRouter::resolveSubscriberChannels([], null, false));
        // Admin: empty requested → defaults to ['admin'].
        self::assertSame(['admin'], BroadcastRouter::resolveSubscriberChannels([], null, true));
    }

    #[Test]
    public function resolve_subscriber_channels_keeps_non_privileged_channel_for_everyone(): void
    {
        // A non-privileged channel (e.g. `story`) must pass through regardless of the flag.
        self::assertSame(['story'], BroadcastRouter::resolveSubscriberChannels(['story'], null, false));
        self::assertSame(['story'], BroadcastRouter::resolveSubscriberChannels(['story'], null, true));
    }

    #[Test]
    public function resolve_subscriber_channels_session_isolation_still_enforced(): void
    {
        // A `session:` channel in $requested must be stripped; the own-session
        // channel must be appended. Privileged channel stripped for non-admin.
        self::assertSame(
            ['session:own'],
            BroadcastRouter::resolveSubscriberChannels(['admin', 'session:abc'], 'session:own', false),
        );
    }

    // --- per-channel ACL: accountMayAccessPrivilegedChannels() ---

    #[Test]
    public function account_may_access_privileged_channels_false_for_anonymous(): void
    {
        // Default stub: isAuthenticated() returns false (default bool = false).
        $anon = $this->createStub(\Waaseyaa\Access\AccountInterface::class);
        $anon->method('isAuthenticated')->willReturn(false);

        // Use reflection to call the private static method.
        $ref = new \ReflectionMethod(BroadcastRouter::class, 'accountMayAccessPrivilegedChannels');
        self::assertFalse($ref->invoke(null, $anon));
    }

    #[Test]
    public function account_may_access_privileged_channels_false_for_authenticated_non_admin(): void
    {
        $authed = $this->createStub(\Waaseyaa\Access\AccountInterface::class);
        $authed->method('isAuthenticated')->willReturn(true);
        $authed->method('getRoles')->willReturn([]);
        $authed->method('hasPermission')->willReturn(false);

        $ref = new \ReflectionMethod(BroadcastRouter::class, 'accountMayAccessPrivilegedChannels');
        self::assertFalse($ref->invoke(null, $authed));
    }

    #[Test]
    public function account_may_access_privileged_channels_true_for_administrator_role(): void
    {
        $admin = $this->createStub(\Waaseyaa\Access\AccountInterface::class);
        $admin->method('isAuthenticated')->willReturn(true);
        $admin->method('getRoles')->willReturn(['administrator']);
        $admin->method('hasPermission')->willReturn(false);

        $ref = new \ReflectionMethod(BroadcastRouter::class, 'accountMayAccessPrivilegedChannels');
        self::assertTrue($ref->invoke(null, $admin));
    }

    #[Test]
    public function account_may_access_privileged_channels_true_for_administer_site_permission(): void
    {
        $admin = $this->createStub(\Waaseyaa\Access\AccountInterface::class);
        $admin->method('isAuthenticated')->willReturn(true);
        $admin->method('getRoles')->willReturn([]);
        $admin->method('hasPermission')->willReturnMap([['administer site', true]]);

        $ref = new \ReflectionMethod(BroadcastRouter::class, 'accountMayAccessPrivilegedChannels');
        self::assertTrue($ref->invoke(null, $admin));
    }
}
