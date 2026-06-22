<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Api\Controller\MercureMonitorController;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorInterface;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorRow;
use Waaseyaa\Api\MercureMonitor\EventStreamFilter;
use Waaseyaa\Api\MercureMonitor\EventStreamReadModelInterface;
use Waaseyaa\Api\MercureMonitor\SubscriberObserverInterface;
use Waaseyaa\Api\MercureMonitor\SubscriberRow;

/**
 * Unit tests for MercureMonitorController (M5D WP01 T004).
 *
 * Uses anonymous-class fakes for each interface (PHPUnit createMock() cannot
 * mock interfaces with readonly return types in PHP 8.5).
 *
 * Mirrors the M4B QueueController / NotificationController test shape.
 */
#[CoversClass(MercureMonitorController::class)]
final class MercureMonitorControllerTest extends TestCase
{
    // --- channels() ---

    #[Test]
    public function channelsReturnsMappedCamelCaseRowsFromInspector(): void
    {
        $inspector = new class implements ChannelInspectorInterface {
            public function listChannels(): array
            {
                return [
                    new ChannelInspectorRow('admin', 42, 1748123456.789, 'entity.saved'),
                    new ChannelInspectorRow('realtime', 7, null, null),
                ];
            }
        };

        $controller = new MercureMonitorController($inspector);
        $result = $controller->channels(new Request());

        self::assertSame('admin', $result['data']['rows'][0]['channel']);
        self::assertSame(42, $result['data']['rows'][0]['eventCount24h']);
        self::assertSame(1748123456.789, $result['data']['rows'][0]['lastEventAt']);
        self::assertSame('entity.saved', $result['data']['rows'][0]['lastEventName']);
        self::assertSame('realtime', $result['data']['rows'][1]['channel']);
        self::assertNull($result['data']['rows'][1]['lastEventAt']);
        self::assertNull($result['data']['rows'][1]['lastEventName']);
    }

    #[Test]
    public function channelsReturnsEmptyShapeWhenInspectorIsNull(): void
    {
        $controller = new MercureMonitorController();
        $result = $controller->channels(new Request());

        self::assertSame(['data' => ['rows' => []]], $result);
    }

    // --- subscribers() ---

    #[Test]
    public function subscribersReturnsMappedCamelCaseRows(): void
    {
        $observer = new class implements SubscriberObserverInterface {
            public function currentSubscribers(): array
            {
                return [
                    new SubscriberRow(42, 'Alice', ['admin'], 1748000000.0),
                    new SubscriberRow(0, null, ['realtime'], 1748000001.0),
                ];
            }
        };

        $controller = new MercureMonitorController(null, null, $observer);
        $result = $controller->subscribers(new Request());

        self::assertSame(42, $result['data']['rows'][0]['accountId']);
        self::assertSame('Alice', $result['data']['rows'][0]['accountLabel']);
        self::assertSame(['admin'], $result['data']['rows'][0]['channels']);
        self::assertSame(0, $result['data']['rows'][1]['accountId']);
        self::assertNull($result['data']['rows'][1]['accountLabel']);
    }

    #[Test]
    public function subscribersReturnsEmptyShapeWhenObserverIsNull(): void
    {
        $controller = new MercureMonitorController();
        $result = $controller->subscribers(new Request());

        self::assertSame(['data' => ['rows' => []]], $result);
    }

    // --- events() ---

    #[Test]
    public function eventsReturnsSseHeadersWhenStreamDepPresent(): void
    {
        $stream = new class implements EventStreamReadModelInterface {
            public function recentEvents(EventStreamFilter $filter, int $limit = 100): array
            {
                return [];
            }
        };

        $controller = new MercureMonitorController(null, $stream);
        $response = $controller->events(new Request());

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        self::assertSame('keep-alive', $response->headers->get('Connection'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    #[Test]
    public function eventsEmitsDisabledFrameWhenStreamDepIsNull(): void
    {
        $controller = new MercureMonitorController();
        $response = $controller->events(new Request());

        self::assertInstanceOf(StreamedResponse::class, $response);
        // SSE headers still set even when stream dep is null
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
        self::assertSame('200', (string) $response->getStatusCode());

        // The disabled-frame path is covered by the header assertions above plus
        // the SSE-headers test. The callback output is flushed to stdout before
        // ob_get_clean() can capture it (ob_flush() inside the callback), so we
        // verify the response is correctly configured as a StreamedResponse with
        // SSE headers rather than inspecting the raw bytes in a unit test.
        // The integration test exercises the full endpoint path.
    }

    // --- events() bounded-loop teardown (CL-12 / #1704) ---

    #[Test]
    public function streamShouldContinueStopsOnDisconnectOrTimeBudget(): void
    {
        // Connected and within budget → keep streaming.
        self::assertTrue(MercureMonitorController::streamShouldContinue(0, 0, 30));
        self::assertTrue(MercureMonitorController::streamShouldContinue(0, 29, 30));
        // Client disconnected → stop, release the worker.
        self::assertFalse(MercureMonitorController::streamShouldContinue(1, 0, 30));
        // Time budget elapsed → stop (no longer an unbounded loop).
        self::assertFalse(MercureMonitorController::streamShouldContinue(0, 30, 30));
        self::assertFalse(MercureMonitorController::streamShouldContinue(0, 31, 30));
    }

    #[Test]
    public function eventsStreamExitsWhenTimeBudgetElapses(): void
    {
        // Monotonic fake clock reaches the per-connection budget deterministically;
        // abort stays 0 (client connected). Before CL-12 this would loop forever.
        $now = 0;
        $clock = function () use (&$now): int {
            return $now++;
        };

        $controller = new MercureMonitorController(
            stream: $this->emptyStream(),
            maxDurationSec: 4,
            keepaliveIntervalSec: 1,
            pollIntervalUs: 0,
            clock: $clock,
            abortSignal: static fn(): int => 0,
        );

        $out = $this->runStream($controller->events(new Request()));

        // Returned (no hang), announced the stream, and emitted a keepalive.
        self::assertStringContainsString('event: connected', $out);
        self::assertStringContainsString(': keepalive', $out);
    }

    #[Test]
    public function eventsStreamExitsPromptlyOnClientDisconnect(): void
    {
        // abort returns 0 once (enter the loop) then 1 (client navigated away):
        // the stream must end on the disconnect, well before the 30s budget.
        $calls = 0;
        $abort = function () use (&$calls): int {
            return $calls++ === 0 ? 0 : 1;
        };

        $controller = new MercureMonitorController(
            stream: $this->emptyStream(),
            maxDurationSec: 30,
            keepaliveIntervalSec: 30,
            pollIntervalUs: 0,
            abortSignal: $abort,
        );

        $out = $this->runStream($controller->events(new Request()));
        self::assertStringContainsString('event: connected', $out);
    }

    #[Test]
    public function eventsEmitsDisabledFrameWhenStreamNullCaptured(): void
    {
        $controller = new MercureMonitorController();
        $out = $this->runStream($controller->events(new Request()));
        self::assertStringContainsString('event: disabled', $out);
    }

    private function emptyStream(): EventStreamReadModelInterface
    {
        return new class implements EventStreamReadModelInterface {
            public function recentEvents(EventStreamFilter $filter, int $limit = 100): array
            {
                return [];
            }
        };
    }

    /**
     * Run the streamed-response callback to completion, capturing emitted bytes.
     * Nested output buffers so the handler's ob_flush()/flush() pair lands in a
     * buffer we can read instead of the SAPI.
     */
    private function runStream(StreamedResponse $response): string
    {
        ob_start();      // capture sink
        ob_start();      // where echo writes; handler's ob_flush() pushes into the sink
        ($response->getCallback())();
        ob_end_flush();  // merge inner remainder into the sink
        return (string) ob_get_clean();
    }
}
