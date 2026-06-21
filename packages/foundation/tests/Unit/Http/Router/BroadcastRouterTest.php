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
        $request = Request::create('/api/broadcast?channels=admin');
        $request->attributes->set('_controller', 'broadcast');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', $broadcastStorage);
        $request->attributes->set('_parsed_body', null);
        $request->attributes->set('_waaseyaa_context',
            \Waaseyaa\Foundation\Http\Router\WaaseyaaContext::fromRequest($request)
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
        $account = $this->createStub(\Waaseyaa\Access\AccountInterface::class);
        $request = Request::create('/api/broadcast?channels=admin');
        $request->attributes->set('_controller', 'broadcast');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', $storage);
        $request->attributes->set('_parsed_body', null);
        $request->attributes->set('_waaseyaa_context',
            \Waaseyaa\Foundation\Http\Router\WaaseyaaContext::fromRequest($request)
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
        $clock = function () use (&$now): int { return $now++; };

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
        $abort = function () use (&$calls): int { return $calls++ === 0 ? 0 : 1; };

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
        $clock = function () use (&$now): int { return $now++; };
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
        $clock = function () use (&$now): int { return $now++; };
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
            $clock = function () use (&$now): int { return $now++; };
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
}
