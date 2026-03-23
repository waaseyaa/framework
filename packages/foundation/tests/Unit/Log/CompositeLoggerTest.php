<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\CompositeLogger;
use Waaseyaa\Foundation\Log\ErrorLogHandler;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\NullLogger;

#[CoversClass(CompositeLogger::class)]
final class CompositeLoggerTest extends TestCase
{
    #[Test]
    public function routes_to_multiple_loggers(): void
    {
        $linesA = [];
        $linesB = [];

        $loggerA = new ErrorLogHandler(static function (string $line) use (&$linesA): void {
            $linesA[] = $line;
        });
        $loggerB = new ErrorLogHandler(static function (string $line) use (&$linesB): void {
            $linesB[] = $line;
        });

        $composite = new CompositeLogger($loggerA, $loggerB);
        $composite->error('test message');

        $this->assertCount(1, $linesA);
        $this->assertCount(1, $linesB);
        $this->assertSame('[error] test message', $linesA[0]);
        $this->assertSame('[error] test message', $linesB[0]);
    }

    #[Test]
    public function continues_when_one_logger_throws(): void
    {
        $lines = [];

        $brokenLogger = new class implements \Waaseyaa\Foundation\Log\LoggerInterface {
            use \Waaseyaa\Foundation\Log\LoggerTrait;

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('Broken sink');
            }
        };

        $workingLogger = new ErrorLogHandler(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $composite = new CompositeLogger($brokenLogger, $workingLogger);
        $composite->error('still delivered');

        $this->assertCount(1, $lines);
        $this->assertSame('[error] still delivered', $lines[0]);
    }

    #[Test]
    public function works_with_no_loggers(): void
    {
        $composite = new CompositeLogger();
        $composite->info('nothing happens');

        $this->addToAssertionCount(1);
    }
}
