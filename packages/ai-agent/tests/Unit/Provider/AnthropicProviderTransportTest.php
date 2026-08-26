<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Provider\AnthropicProvider;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\ProviderTimeouts;
use Waaseyaa\AI\Agent\Provider\StreamChunk;
use Waaseyaa\AI\Agent\Provider\TransportException;
use Waaseyaa\AI\Agent\Tests\Support\StallingTransportServer;

/**
 * Transport-level time bounds for AnthropicProvider (#2156), pinned against a
 * real local peer that stalls.
 *
 * The shipped defaults are deliberately measured in tens of seconds, so the
 * behavioural tests here run the same code paths with the same profiles scaled
 * down by two orders of magnitude; {@see ProviderTimeoutsTest} pins the shipped
 * numbers themselves, and {@see defaultStreamProfileBoundsAStallLongBeforeTheTotal}
 * pins the profile the provider actually installs for streaming.
 */
#[CoversClass(AnthropicProvider::class)]
final class AnthropicProviderTransportTest extends TestCase
{
    /**
     * Upper bounds, not expected durations. Measured locally: a stalled
     * handshake fails in ~1.1s and the low-speed teardown in ~5.5s (libcurl
     * averages transfer speed over a rolling window, so the abort trails the
     * configured window by a few seconds). The margins keep a loaded CI box
     * from turning a real bound into a flake.
     */
    private const HANDSHAKE_BOUND_SECONDS = 15.0;
    private const LOW_SPEED_BOUND_SECONDS = 25.0;

    /** @var list<StallingTransportServer> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];
    }

    // ---- the connection phase ---------------------------------------------

    #[Test]
    public function aStalledHandshakeFailsOnTheConnectBoundNotTheTotal(): void
    {
        $server = $this->startServer(StallingTransportServer::MODE_SILENT);
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            baseUrl: $server->tlsBaseUrl(),
            streamTimeouts: new ProviderTimeouts(connectSeconds: 1.0, totalSeconds: 60.0),
        );

        $chunks = [];
        $record = static function (StreamChunk $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        };

        $elapsed = $this->timeFailure(
            fn(): mixed => $provider->streamMessage(self::request(), $record),
            $server,
        );

        self::assertLessThan(self::HANDSHAKE_BOUND_SECONDS, $elapsed);
        self::assertSame([], $chunks, 'A stalled handshake must not deliver chunks.');
    }

    #[Test]
    public function aStalledHandshakeAlsoBoundsTheNonStreamingPath(): void
    {
        $server = $this->startServer(StallingTransportServer::MODE_SILENT);
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            baseUrl: $server->tlsBaseUrl(),
            timeouts: new ProviderTimeouts(connectSeconds: 1.0, totalSeconds: 60.0),
        );

        $elapsed = $this->timeFailure(fn(): mixed => $provider->sendMessage(self::request()), $server);

        self::assertLessThan(self::HANDSHAKE_BOUND_SECONDS, $elapsed);
    }

    // ---- the stalled stream ------------------------------------------------

    #[Test]
    public function withoutALowSpeedGuardAStalledStreamHoldsTheWorkerForTheWholeTotal(): void
    {
        // The reported bug, reproduced at 1/100 scale: the peer answers, emits
        // one delta, then goes silent. Nothing but CURLOPT_TIMEOUT ends the
        // call, and the caller's own budget cannot help — it only runs when a
        // chunk arrives, and no further chunk ever arrives.
        $server = $this->startServer(StallingTransportServer::MODE_STALL);
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            baseUrl: $server->baseUrl(),
            streamTimeouts: new ProviderTimeouts(connectSeconds: 1.0, totalSeconds: 3.0),
        );

        $callbackRuns = 0;
        $count = static function (StreamChunk $chunk) use (&$callbackRuns): void {
            $callbackRuns++;
        };

        $elapsed = $this->timeFailure(
            fn(): mixed => $provider->streamMessage(self::request(), $count),
            $server,
        );

        self::assertSame(1, $callbackRuns, 'The stream must stall after exactly one delta.');
        self::assertGreaterThan(2.5, $elapsed, 'Without a low-speed guard the total timeout is the only bound.');
        self::assertLessThan(20.0, $elapsed);
    }

    #[Test]
    public function aLowSpeedGuardTearsDownAStalledStreamLongBeforeTheTotal(): void
    {
        $server = $this->startServer(StallingTransportServer::MODE_STALL);
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            baseUrl: $server->baseUrl(),
            streamTimeouts: new ProviderTimeouts(
                connectSeconds: 1.0,
                totalSeconds: 60.0,
                lowSpeedBytesPerSecond: 1,
                lowSpeedSeconds: 1,
            ),
        );

        $elapsed = $this->timeFailure(
            fn(): mixed => $provider->streamMessage(self::request(), static fn(StreamChunk $chunk): null => null),
            $server,
        );

        self::assertLessThan(
            self::LOW_SPEED_BOUND_SECONDS,
            $elapsed,
            'The low-speed guard, not the 60s total, must release the worker.',
        );
    }

    #[Test]
    public function defaultStreamProfileBoundsAStallLongBeforeTheTotal(): void
    {
        // The shipped streaming profile is what production runs; the behavioural
        // tests above scale it down so the suite stays fast.
        $installed = self::installedTimeouts(new AnthropicProvider(apiKey: 'test-key'), 'streamTimeouts');

        self::assertSame(5.0, $installed->connectSeconds);
        self::assertSame(300.0, $installed->totalSeconds);
        self::assertSame(1, $installed->lowSpeedBytesPerSecond);
        self::assertSame(30, $installed->lowSpeedSeconds);
    }

    // ---- healthy traffic is unchanged --------------------------------------

    #[Test]
    public function aHealthyStreamStillCompletesUnderTheShippedDefaults(): void
    {
        $server = $this->startServer(StallingTransportServer::MODE_SSE);
        $provider = new AnthropicProvider(apiKey: 'test-key', baseUrl: $server->baseUrl());

        $text = '';
        $response = $provider->streamMessage(
            self::request(),
            static function (StreamChunk $chunk) use (&$text): void {
                if ($chunk->type === 'text_delta') {
                    $text .= $chunk->text;
                }
            },
        );

        self::assertSame('Hello world', $text);
        self::assertSame('Hello world', $response->getText());
        self::assertSame('end_turn', $response->stopReason);
    }

    #[Test]
    public function aHealthyRequestStillCompletesUnderTheShippedDefaults(): void
    {
        $server = $this->startServer(StallingTransportServer::MODE_JSON);
        $provider = new AnthropicProvider(apiKey: 'test-key', baseUrl: $server->baseUrl());

        $response = $provider->sendMessage(self::request());

        self::assertSame('Hello world', $response->getText());
        self::assertSame('end_turn', $response->stopReason);
    }

    // ---- helpers -----------------------------------------------------------

    private function startServer(string $mode): StallingTransportServer
    {
        $server = new StallingTransportServer($mode);
        $this->servers[] = $server;

        return $server;
    }

    /**
     * Run an operation that must fail on a transport bound, and return how long
     * the worker was held. Also pins that the failure stays sanitized: a timeout
     * crosses the credential-custody boundary as the fixed taxonomy message, and
     * never carries the endpoint back to the caller.
     *
     * @param \Closure(): mixed $operation
     */
    private function timeFailure(\Closure $operation, StallingTransportServer $server): float
    {
        $startedAt = microtime(true);

        try {
            $operation();
        } catch (TransportException $failure) {
            $elapsed = microtime(true) - $startedAt;

            self::assertSame('Provider transport unavailable.', $failure->getMessage());
            self::assertStringNotContainsString((string) $server->port, $failure->getMessage());

            return $elapsed;
        }

        self::fail('A stalled peer must surface as a TransportException.');
    }

    private static function installedTimeouts(AnthropicProvider $provider, string $property): ProviderTimeouts
    {
        $value = (new \ReflectionProperty($provider, $property))->getValue($provider);
        self::assertInstanceOf(ProviderTimeouts::class, $value);

        return $value;
    }

    private static function request(): MessageRequest
    {
        return new MessageRequest(
            messages: [['role' => 'user', 'content' => 'Hello']],
            maxTokens: 64,
        );
    }
}
