<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\NullLogger;

#[CoversClass(NullLogger::class)]
final class NullLoggerTest extends TestCase
{
    #[Test]
    public function discards_all_messages_without_error(): void
    {
        $logger = new NullLogger();

        // Should not throw or produce any side effects.
        $logger->emergency('test');
        $logger->alert('test');
        $logger->critical('test');
        $logger->error('test');
        $logger->warning('test');
        $logger->notice('test');
        $logger->info('test');
        $logger->debug('test');
        $logger->log(LogLevel::ERROR, 'test', ['key' => 'value']);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function implements_logger_interface(): void
    {
        $logger = new NullLogger();

        $this->assertInstanceOf(\Waaseyaa\Foundation\Log\LoggerInterface::class, $logger);
    }
}
