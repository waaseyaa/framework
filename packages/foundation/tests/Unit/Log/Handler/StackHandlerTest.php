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
    public function failing_handler_emits_only_a_fixed_non_sensitive_code(): void
    {
        $logFile = sys_get_temp_dir() . '/waaseyaa-cfg04-handler-' . bin2hex(random_bytes(6)) . '.log';
        $previousLog = ini_set('error_log', $logFile);

        try {
            $handler = new class implements HandlerInterface {
                public function handle(LogRecord $record): void
                {
                    throw new \RuntimeException('cfg04-handler-exception-canary');
                }
            };
            $stack = new StackHandler($handler);

            $stack->handle(new LogRecord(LogLevel::ERROR, 'safe record'));

            $output = file_get_contents($logFile);
            $this->assertIsString($output);
            $this->assertStringContainsString('LOG_HANDLER_FAILURE', $output);
            $this->assertStringNotContainsString('cfg04-handler-exception-canary', $output);
        } finally {
            if (is_string($previousLog)) {
                ini_set('error_log', $previousLog);
            }
            if (file_exists($logFile)) {
                unlink($logFile);
            }
        }
    }
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
