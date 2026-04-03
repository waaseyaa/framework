<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\Handler\StreamHandler;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

#[CoversClass(StreamHandler::class)]
final class StreamHandlerTest extends TestCase
{
    #[Test]
    public function handle_writes_to_stream(): void
    {
        $stream = fopen('php://memory', 'rw');
        $handler = new StreamHandler($stream);

        $handler->handle(new LogRecord(LogLevel::ERROR, 'stream test'));

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        $this->assertStringContainsString('[error]', $output);
        $this->assertStringContainsString('stream test', $output);
    }

    #[Test]
    public function handle_respects_minimum_level(): void
    {
        $stream = fopen('php://memory', 'rw');
        $handler = new StreamHandler($stream, minimumLevel: LogLevel::WARNING);

        $handler->handle(new LogRecord(LogLevel::DEBUG, 'dropped'));

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame('', $output);
    }

    #[Test]
    public function constructor_rejects_non_resource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StreamHandler('not-a-resource');
    }
}
