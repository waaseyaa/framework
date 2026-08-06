<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Testing\Clock\MutableEntityClock;

#[CoversClass(MutableEntityClock::class)]
final class MutableEntityClockTest extends TestCase
{
    #[Test]
    public function it_moves_time_explicitly_without_reading_the_system_clock(): void
    {
        $clock = new MutableEntityClock(new \DateTimeImmutable('2026-08-05T12:00:00+00:00'));

        self::assertSame('2026-08-05T12:00:00+00:00', $clock->now()->format(DATE_ATOM));

        $clock->advance(new \DateInterval('PT90S'));
        self::assertSame('2026-08-05T12:01:30+00:00', $clock->now()->format(DATE_ATOM));

        $clock->set(new \DateTimeImmutable('2026-08-06T08:30:00+00:00'));
        self::assertSame('2026-08-06T08:30:00+00:00', $clock->now()->format(DATE_ATOM));
    }
}
