<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(LogLevel::class)]
final class LogLevelSeverityTest extends TestCase
{
    #[Test]
    public function emergency_is_highest_severity(): void
    {
        $this->assertGreaterThan(
            LogLevel::ALERT->severity(),
            LogLevel::EMERGENCY->severity(),
        );
    }

    #[Test]
    public function debug_is_lowest_severity(): void
    {
        $this->assertLessThan(
            LogLevel::INFO->severity(),
            LogLevel::DEBUG->severity(),
        );
    }

    #[Test]
    public function severity_ordering_matches_rfc_5424(): void
    {
        $expected = [
            LogLevel::DEBUG,
            LogLevel::INFO,
            LogLevel::NOTICE,
            LogLevel::WARNING,
            LogLevel::ERROR,
            LogLevel::CRITICAL,
            LogLevel::ALERT,
            LogLevel::EMERGENCY,
        ];

        for ($i = 1; $i < count($expected); $i++) {
            $this->assertGreaterThan(
                $expected[$i - 1]->severity(),
                $expected[$i]->severity(),
                sprintf('%s should be more severe than %s', $expected[$i]->value, $expected[$i - 1]->value),
            );
        }
    }

    #[Test]
    public function from_name_resolves_valid_level(): void
    {
        $this->assertSame(LogLevel::WARNING, LogLevel::fromName('warning'));
        $this->assertSame(LogLevel::DEBUG, LogLevel::fromName('DEBUG'));
        $this->assertSame(LogLevel::ERROR, LogLevel::fromName('Error'));
    }

    #[Test]
    public function from_name_returns_null_for_invalid_level(): void
    {
        $this->assertNull(LogLevel::fromName('invalid'));
        $this->assertNull(LogLevel::fromName(''));
    }
}
