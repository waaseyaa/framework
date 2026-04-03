<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\ErrorLogHandler;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(ErrorLogHandler::class)]
final class ErrorLogHandlerMinimumLevelTest extends TestCase
{
    #[Test]
    public function discards_messages_below_minimum_level(): void
    {
        $messages = [];
        $handler = new ErrorLogHandler(
            writer: static function (string $line) use (&$messages): void {
                $messages[] = $line;
            },
            minimumLevel: LogLevel::WARNING,
        );

        $handler->debug('should be discarded');
        $handler->info('should be discarded');
        $handler->notice('should be discarded');

        $this->assertSame([], $messages);
    }

    #[Test]
    public function passes_messages_at_or_above_minimum_level(): void
    {
        $messages = [];
        $handler = new ErrorLogHandler(
            writer: static function (string $line) use (&$messages): void {
                $messages[] = $line;
            },
            minimumLevel: LogLevel::WARNING,
        );

        $handler->warning('should pass');
        $handler->error('should pass');
        $handler->critical('should pass');

        $this->assertCount(3, $messages);
    }

    #[Test]
    public function defaults_to_debug_when_no_minimum_set(): void
    {
        $messages = [];
        $handler = new ErrorLogHandler(
            writer: static function (string $line) use (&$messages): void {
                $messages[] = $line;
            },
        );

        $handler->debug('lowest level');

        $this->assertCount(1, $messages);
    }
}
