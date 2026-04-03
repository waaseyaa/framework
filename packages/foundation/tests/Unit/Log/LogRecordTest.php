<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

#[CoversClass(LogRecord::class)]
final class LogRecordTest extends TestCase
{
    #[Test]
    public function properties_are_accessible(): void
    {
        $record = new LogRecord(
            level: LogLevel::ERROR,
            message: 'Something broke',
            context: ['key' => 'value'],
            channel: 'security',
        );

        $this->assertSame(LogLevel::ERROR, $record->level);
        $this->assertSame('Something broke', $record->message);
        $this->assertSame(['key' => 'value'], $record->context);
        $this->assertSame('security', $record->channel);
        $this->assertInstanceOf(\DateTimeImmutable::class, $record->timestamp);
    }

    #[Test]
    public function channel_defaults_to_default(): void
    {
        $record = new LogRecord(level: LogLevel::INFO, message: 'test');

        $this->assertSame('default', $record->channel);
    }

    #[Test]
    public function context_defaults_to_empty_array(): void
    {
        $record = new LogRecord(level: LogLevel::DEBUG, message: 'test');

        $this->assertSame([], $record->context);
    }

    #[Test]
    public function custom_timestamp_is_preserved(): void
    {
        $ts = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $record = new LogRecord(level: LogLevel::INFO, message: 'test', timestamp: $ts);

        $this->assertSame($ts, $record->timestamp);
    }
}
