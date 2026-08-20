<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Provider\ProviderTimeouts;

#[CoversClass(ProviderTimeouts::class)]
final class ProviderTimeoutsTest extends TestCase
{
    #[Test]
    public function theRequestProfileBoundsTheConnectionPhaseSeparatelyFromTheTotal(): void
    {
        $options = ProviderTimeouts::forRequest()->curlOptions();

        self::assertSame(5_000, $options[\CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame(120_000, $options[\CURLOPT_TIMEOUT_MS]);
    }

    #[Test]
    public function theRequestProfileLeavesTheLowSpeedAbortOff(): void
    {
        // A non-streaming call is legitimately silent while the model generates,
        // so there is no byte rate to hold it to.
        $options = ProviderTimeouts::forRequest()->curlOptions();

        self::assertArrayNotHasKey(\CURLOPT_LOW_SPEED_LIMIT, $options);
        self::assertArrayNotHasKey(\CURLOPT_LOW_SPEED_TIME, $options);
    }

    #[Test]
    public function theStreamingProfileAbortsAStalledTransferLongBeforeItsTotal(): void
    {
        $streaming = ProviderTimeouts::forStreaming();
        $options = $streaming->curlOptions();

        self::assertSame(300.0, $streaming->totalSeconds, 'A healthy long generation must still be allowed.');
        self::assertSame(5_000, $options[\CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame(300_000, $options[\CURLOPT_TIMEOUT_MS]);
        self::assertSame(1, $options[\CURLOPT_LOW_SPEED_LIMIT]);
        self::assertSame(30, $options[\CURLOPT_LOW_SPEED_TIME]);
    }

    #[Test]
    public function callersCanTightenEveryBound(): void
    {
        $options = (new ProviderTimeouts(
            connectSeconds: 2.5,
            totalSeconds: 45.0,
            lowSpeedBytesPerSecond: 8,
            lowSpeedSeconds: 10,
        ))->curlOptions();

        self::assertSame(2_500, $options[\CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame(45_000, $options[\CURLOPT_TIMEOUT_MS]);
        self::assertSame(8, $options[\CURLOPT_LOW_SPEED_LIMIT]);
        self::assertSame(10, $options[\CURLOPT_LOW_SPEED_TIME]);
    }

    #[Test]
    public function aSubMillisecondBoundStillReachesCurlAsABound(): void
    {
        // Rounding to zero would hand libcurl "no timeout" — the opposite of intent.
        $options = (new ProviderTimeouts(connectSeconds: 0.0001, totalSeconds: 0.0001))->curlOptions();

        self::assertSame(1, $options[\CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame(1, $options[\CURLOPT_TIMEOUT_MS]);
    }

    #[Test]
    public function anUnboundedTimeoutIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be positive');

        new ProviderTimeouts(connectSeconds: 5.0, totalSeconds: 0.0);
    }

    #[Test]
    public function aConnectBoundLongerThanTheTotalIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('can never be reached');

        new ProviderTimeouts(connectSeconds: 30.0, totalSeconds: 10.0);
    }

    #[Test]
    public function aNegativeLowSpeedLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be negative');

        new ProviderTimeouts(lowSpeedBytesPerSecond: -1, lowSpeedSeconds: 30);
    }

    #[Test]
    public function halfALowSpeedAbortIsRejected(): void
    {
        // A byte floor with no window (or the reverse) silently does nothing in
        // libcurl; refuse it rather than pretend the transfer is guarded.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('set both or neither');

        new ProviderTimeouts(lowSpeedBytesPerSecond: 1);
    }
}
