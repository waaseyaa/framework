<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LegacyLoggerHandler;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

#[CoversClass(LegacyLoggerHandler::class)]
final class LegacyLoggerHandlerTest extends TestCase
{
    #[Test]
    public function delegates_to_wrapped_logger(): void
    {
        $calls = [];
        $legacy = new class ($calls) implements LoggerInterface {
            use LoggerTrait;

            public function __construct(private array &$calls) {}

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->calls[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $handler = new LegacyLoggerHandler($legacy);
        $record = new LogRecord(LogLevel::WARNING, 'legacy msg', ['key' => 'val']);
        $handler->handle($record);

        $this->assertCount(1, $calls);
        $this->assertSame(LogLevel::WARNING, $calls[0]['level']);
        $this->assertSame('legacy msg', $calls[0]['message']);
        $this->assertSame(['key' => 'val'], $calls[0]['context']);
    }
}
