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
        $timeouts = new ProviderTimeouts(connectSeconds: 1.0, totalSeconds: 3.0);

        // #2716 (ported from OpenAiCompatibleProviderTransportTest, a28220d95 /
        // #2795): a wall-clock lower bound cannot tell "the total timeout
        // fired" apart from "the peer connection died early for an unrelated
        // reason" — both just look like "returned sometime before the upper
        // bound". cURL's own failure reason does distinguish them
        // deterministically: classify on that instead, against the exact
        // option set the provider installs ({@see ProviderTimeouts::curlOptions()}),
        // before ever going through {@see AnthropicProvider} — so an early
        // teardown fails loudly on the classification rather than being
        // absorbed by a timing miss.
        $this->assertPeerEndsOnlyOnTheTotalTimeout($server, $timeouts);

        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            baseUrl: $server->baseUrl(),
            streamTimeouts: $timeouts,
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

        // #2716 review follow-up: the probe above proves the *peer and option
        // set* are capable of producing "held to the total" — but it runs on
        // its own fresh connection, before streamMessage() ever dials out, so
        // it cannot speak to why the real (SUT) connection ended.
        // TransportException deliberately cannot say either — the wrapping
        // ProviderCredentialOutcome::capture()/unwrap() strips the original
        // cURL errno/message before it reaches the caller by design (never
        // leak transport detail across the credential boundary), so there is
        // no test-only way to classify the SUT's own connection the way
        // assertPeerEndsOnlyOnTheTotalTimeout() classifies the probe's. A
        // wall-clock floor is the only signal available for *this*
        // connection.
        //
        // #2827 root cause (ported from #2716/a28220d95): that floor was
        // flaky not because of the peer or the transport, but because
        // {@see timeFailure()} measured it with `microtime(true)` — the wall
        // clock (CLOCK_REALTIME). On a WSL2 / Hyper-V guest that clock is
        // periodically stepped backward by the host's time-sync correction,
        // which can make a call that was genuinely held for the full total
        // timeout measure as having returned almost instantly. `timeFailure()`
        // now measures with `hrtime(true)` (CLOCK_MONOTONIC), which a
        // wall-clock step cannot move, so this floor is back to being a
        // trustworthy signal. Restored to the original 2.5s — a wide margin
        // under the 3.0s total for genuine scheduling jitter on a loaded box,
        // now that a clock step can no longer manufacture a false reading
        // under it.
        self::assertGreaterThan(
            2.5,
            $elapsed,
            'The real (SUT) connection ended too early to have been held by the total timeout — '
                . 'an early teardown, not a stall.',
        );
        self::assertLessThan(20.0, $elapsed);
    }

    /**
     * Issue a raw request with the provider's exact timeout wiring and assert
     * cURL's own classification of why it ended, rather than inferring the
     * reason from elapsed wall-clock time. `CURLE_OPERATION_TIMEDOUT` alone is
     * not enough — libcurl reports the same code for a connect-phase timeout —
     * so the message text is pinned too: only the total bound firing mid-transfer
     * reports a partial byte count, which is what proves this was a stalled
     * stream held to the total, not a connection that never got past the
     * handshake or died for some unrelated reason (connection refused, reset,
     * empty reply all report distinct, unmistakably different cURL errors).
     *
     * This runs against its own connection rather than the SUT's — see the
     * wall-clock floor on the real call in
     * {@see withoutALowSpeedGuardAStalledStreamHoldsTheWorkerForTheWholeTotal()}
     * for why the SUT's own connection cannot be classified this way.
     */
    private function assertPeerEndsOnlyOnTheTotalTimeout(
        StallingTransportServer $server,
        ProviderTimeouts $timeouts,
    ): void {
        $handle = \curl_init($server->baseUrl() . '/v1/messages');
        if ($handle === false) {
            self::fail('Failed to initialize cURL for the timeout-classification probe.');
        }

        \curl_setopt_array($handle, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => (string) \json_encode(['probe' => true], \JSON_THROW_ON_ERROR),
            \CURLOPT_RETURNTRANSFER => true,
        ] + $timeouts->curlOptions());

        \curl_exec($handle);
        $errno = \curl_errno($handle);
        $error = \curl_error($handle);

        self::assertSame(
            \CURLE_OPERATION_TIMEDOUT,
            $errno,
            "Expected the total timeout to end the stalled stream; got cURL errno {$errno} ({$error}) "
                . 'instead — an early teardown, not a stall.',
        );
        self::assertStringContainsString(
            'Operation timed out',
            $error,
            'A connect-phase timeout reports a different message than the total bound firing mid-transfer.',
        );
        self::assertStringContainsString(
            'bytes received',
            $error,
            'The total bound must fire mid-transfer, proving the peer delivered a partial stream and then stalled.',
        );
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
     * #2827 (ported from #2716/a28220d95): this used to measure with
     * `microtime(true)`, i.e. the wall clock (CLOCK_REALTIME). On a WSL2 /
     * Hyper-V guest that clock is periodically stepped backward by the host's
     * time-sync correction, which can make a call that was genuinely held for
     * the full total timeout measure as having returned almost instantly — not
     * a real early teardown, just a clock step landing inside the measurement
     * window. `hrtime(true)` reads CLOCK_MONOTONIC, which a wall-clock step
     * cannot move, so it reports the operation's true duration regardless of
     * when a time-sync correction lands.
     *
     * @param \Closure(): mixed $operation
     */
    private function timeFailure(\Closure $operation, StallingTransportServer $server): float
    {
        $startedAt = \hrtime(true);

        try {
            $operation();
        } catch (TransportException $failure) {
            $elapsed = (\hrtime(true) - $startedAt) / 1_000_000_000;

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
