<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\Formatter\JsonFormatter;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

#[CoversClass(JsonFormatter::class)]
final class JsonFormatterTest extends TestCase
{
    #[Test]
    public function format_produces_valid_json(): void
    {
        $ts = new \DateTimeImmutable('2026-01-15T10:30:00+00:00');
        $record = new LogRecord(LogLevel::WARNING, 'slow query', ['ms' => 1200], 'db', $ts);

        $output = (new JsonFormatter())->format($record);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('2026-01-15T10:30:00+00:00', $decoded['timestamp']);
        $this->assertSame('warning', $decoded['level']);
        $this->assertSame('db', $decoded['channel']);
        $this->assertSame('slow query', $decoded['message']);
        $this->assertSame(['ms' => 1200], $decoded['context']);
    }

    #[Test]
    public function format_includes_empty_context(): void
    {
        $record = new LogRecord(LogLevel::INFO, 'test');

        $decoded = json_decode((new JsonFormatter())->format($record), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], $decoded['context']);
    }
}
