<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\Formatter\TextFormatter;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

#[CoversClass(TextFormatter::class)]
final class TextFormatterTest extends TestCase
{
    #[Test]
    public function format_includes_timestamp_level_channel_message(): void
    {
        $ts = new \DateTimeImmutable('2026-01-15T10:30:00+00:00');
        $record = new LogRecord(LogLevel::ERROR, 'disk full', channel: 'system', timestamp: $ts);

        $output = (new TextFormatter())->format($record);

        $this->assertSame('[2026-01-15T10:30:00+00:00] [error] [system] disk full', $output);
    }

    #[Test]
    public function format_appends_context_as_json(): void
    {
        $ts = new \DateTimeImmutable('2026-01-15T10:30:00+00:00');
        $record = new LogRecord(LogLevel::INFO, 'user login', ['uid' => 42], 'auth', $ts);

        $output = (new TextFormatter())->format($record);

        $this->assertStringContainsString('{"uid":42}', $output);
    }

    #[Test]
    public function format_omits_context_when_empty(): void
    {
        $ts = new \DateTimeImmutable('2026-01-15T10:30:00+00:00');
        $record = new LogRecord(LogLevel::DEBUG, 'ping', timestamp: $ts);

        $output = (new TextFormatter())->format($record);

        $this->assertStringNotContainsString('{', $output);
    }
}
