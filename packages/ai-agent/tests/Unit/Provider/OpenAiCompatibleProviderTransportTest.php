<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\OpenAiCompatibleProvider;
use Waaseyaa\AI\Agent\Provider\ProviderTimeouts;
use Waaseyaa\AI\Agent\Provider\TransportException;
use Waaseyaa\AI\Agent\Tests\Support\StallingTransportServer;

/**
 * Transport-level time bounds for OpenAiCompatibleProvider (#2445), pinned
 * against a real local peer that stalls.
 *
 * This provider has no streaming path, so the stall that matters is not a
 * silent SSE stream but a silent *body*: the peer answers, promises a
 * chat completion via Content-Length, delivers a prefix, and stops. There is no
 * chunk callback to notice, so before #2445 only the fixed 120s total ended it.
 *
 * The behavioural tests run the same code paths with scaled-down profiles so the
 * suite stays fast; {@see theShippedProfileKeepsTheHistoricalTotalAndAddsAConnectBound}
 * pins the profile the provider actually installs, and {@see ProviderTimeoutsTest}
 * pins the shipped numbers themselves.
 */
#[CoversClass(OpenAiCompatibleProvider::class)]
final class OpenAiCompatibleProviderTransportTest extends TestCase
{
    /**
     * Upper bounds, not expected durations. Measured locally: a stalled
     * handshake fails in ~1.1s and the low-speed teardown in ~5.5s, because
     * libcurl averages transfer speed over a rolling window and the abort trails
     * the configured window. The margins keep a loaded CI box from turning a real
     * bound into a flake.
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
        // Reproduced before the fix: this peer held the worker past 12s with a
        // 120s total and no connect bound at all.
        $server = $this->startServer(StallingTransportServer::MODE_SILENT);
        $provider = new OpenAiCompatibleProvider(
            apiKey: 'test-key',
            baseUrl: $server->tlsBaseUrl(),
            timeouts: new ProviderTimeouts(connectSeconds: 1.0, totalSeconds: 60.0),
        );

        $elapsed = $this->timeFailure(fn(): mixed => $provider->sendMessage(self::request()), $server);

        self::assertLessThan(self::HANDSHAKE_BOUND_SECONDS, $elapsed);
    }

    // ---- the stalled body ---------------------------------------------------

    #[Test]
    public function withoutALowSpeedGuardAStalledBodyHoldsTheWorkerForTheWholeTotal(): void
    {
        // The reported gap, reproduced at 1/40 scale: the peer promises a
        // completion, delivers a prefix, then goes quiet. Nothing but the total
        // ends the call — and a non-streaming caller has no callback in which to
        // notice, so it cannot compensate the way a streaming caller might try to.
        $server = $this->startServer(StallingTransportServer::MODE_CHAT_STALL);
        $provider = new OpenAiCompatibleProvider(
            apiKey: 'test-key',
            baseUrl: $server->baseUrl(),
            timeouts: new ProviderTimeouts(connectSeconds: 1.0, totalSeconds: 3.0),
        );

        $elapsed = $this->timeFailure(fn(): mixed => $provider->sendMessage(self::request()), $server);

        self::assertGreaterThan(2.5, $elapsed, 'Without a low-speed guard the total timeout is the only bound.');
        self::assertLessThan(20.0, $elapsed);
    }

    #[Test]
    public function aLowSpeedGuardTearsDownAStalledBodyLongBeforeTheTotal(): void
    {
        $server = $this->startServer(StallingTransportServer::MODE_CHAT_STALL);
        $provider = new OpenAiCompatibleProvider(
            apiKey: 'test-key',
            baseUrl: $server->baseUrl(),
            timeouts: new ProviderTimeouts(
                connectSeconds: 1.0,
                totalSeconds: 60.0,
                lowSpeedBytesPerSecond: 1,
                lowSpeedSeconds: 1,
            ),
        );

        $elapsed = $this->timeFailure(fn(): mixed => $provider->sendMessage(self::request()), $server);

        self::assertLessThan(
            self::LOW_SPEED_BOUND_SECONDS,
            $elapsed,
            'The low-speed guard, not the 60s total, must release the worker.',
        );
    }

    // ---- shipped defaults ----------------------------------------------------

    #[Test]
    public function theShippedProfileKeepsTheHistoricalTotalAndAddsAConnectBound(): void
    {
        // The 120s total is the pre-existing caller default and is preserved
        // exactly. The low-speed guard stays off: a chat completion is
        // legitimately silent while the model generates, and libcurl's byte-rate
        // window covers that wait too, so enabling it by default would abort
        // healthy slow generations rather than stalled ones.
        $installed = self::installedTimeouts(new OpenAiCompatibleProvider(apiKey: 'test-key'));

        self::assertSame(5.0, $installed->connectSeconds);
        self::assertSame(120.0, $installed->totalSeconds);
        self::assertSame(0, $installed->lowSpeedBytesPerSecond);
        self::assertSame(0, $installed->lowSpeedSeconds);
    }

    #[Test]
    public function aHealthyRequestStillCompletesUnderTheShippedDefaults(): void
    {
        $server = $this->startServer(StallingTransportServer::MODE_CHAT);
        $provider = new OpenAiCompatibleProvider(apiKey: 'test-key', baseUrl: $server->baseUrl());

        $response = $provider->sendMessage(self::request());

        self::assertSame('Hello world', $response->getText());
        self::assertSame('end_turn', $response->stopReason);
        self::assertSame(7, $response->usage['input_tokens'] ?? null);
        self::assertSame(3, $response->usage['output_tokens'] ?? null);
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
     * never carries the endpoint or the cURL detail back to the caller.
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

    private static function installedTimeouts(OpenAiCompatibleProvider $provider): ProviderTimeouts
    {
        $value = (new \ReflectionProperty($provider, 'timeouts'))->getValue($provider);
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
