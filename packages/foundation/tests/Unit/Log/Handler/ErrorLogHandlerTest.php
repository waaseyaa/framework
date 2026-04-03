<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\Handler\ErrorLogHandler;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

#[CoversClass(ErrorLogHandler::class)]
final class ErrorLogHandlerTest extends TestCase
{
    #[Test]
    public function handle_writes_formatted_record(): void
    {
        $lines = [];
        $handler = new ErrorLogHandler(
            writer: static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $handler->handle(new LogRecord(LogLevel::ERROR, 'test'));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('[error]', $lines[0]);
        $this->assertStringContainsString('test', $lines[0]);
    }

    #[Test]
    public function handle_respects_minimum_level(): void
    {
        $lines = [];
        $handler = new ErrorLogHandler(
            minimumLevel: LogLevel::ERROR,
            writer: static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $handler->handle(new LogRecord(LogLevel::DEBUG, 'dropped'));
        $handler->handle(new LogRecord(LogLevel::ERROR, 'kept'));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('kept', $lines[0]);
    }
}
