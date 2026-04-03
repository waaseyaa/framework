<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\Handler\HandlerInterface;
use Waaseyaa\Foundation\Log\Handler\StackHandler;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

#[CoversClass(StackHandler::class)]
final class StackHandlerTest extends TestCase
{
    #[Test]
    public function delegates_to_all_handlers(): void
    {
        $calls = [];
        $h1 = new class ($calls) implements HandlerInterface {
            public function __construct(private array &$calls) {}

            public function handle(LogRecord $record): void
            {
                $this->calls[] = 'h1:' . $record->message;
            }
        };
        $h2 = new class ($calls) implements HandlerInterface {
            public function __construct(private array &$calls) {}

            public function handle(LogRecord $record): void
            {
                $this->calls[] = 'h2:' . $record->message;
            }
        };

        $stack = new StackHandler($h1, $h2);
        $stack->handle(new LogRecord(LogLevel::INFO, 'test'));

        $this->assertSame(['h1:test', 'h2:test'], $calls);
    }

    #[Test]
    public function continues_after_handler_failure(): void
    {
        $calls = [];
        $failing = new class implements HandlerInterface {
            public function handle(LogRecord $record): void
            {
                throw new \RuntimeException('boom');
            }
        };
        $working = new class ($calls) implements HandlerInterface {
            public function __construct(private array &$calls) {}

            public function handle(LogRecord $record): void
            {
                $this->calls[] = $record->message;
            }
        };

        $stack = new StackHandler($failing, $working);
        $stack->handle(new LogRecord(LogLevel::ERROR, 'survived'));

        $this->assertSame(['survived'], $calls);
    }
}
