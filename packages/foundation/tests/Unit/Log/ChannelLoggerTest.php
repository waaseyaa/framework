<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\ChannelLogger;
use Waaseyaa\Foundation\Log\Handler\HandlerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

#[CoversClass(ChannelLogger::class)]
final class ChannelLoggerTest extends TestCase
{
    #[Test]
    public function stamps_channel_on_record(): void
    {
        $captured = null;
        $handler = new class ($captured) implements HandlerInterface {
            public function __construct(private ?LogRecord &$captured) {}

            public function handle(LogRecord $record): void
            {
                $this->captured = $record;
            }
        };

        $logger = new ChannelLogger('auth', $handler);
        $logger->log(LogLevel::INFO, 'login success', ['uid' => 7]);

        $this->assertNotNull($captured);
        $this->assertSame('auth', $captured->channel);
        $this->assertSame('login success', $captured->message);
        $this->assertSame(LogLevel::INFO, $captured->level);
        $this->assertSame(['uid' => 7], $captured->context);
    }

    #[Test]
    public function convenience_methods_delegate(): void
    {
        $records = [];
        $handler = new class ($records) implements HandlerInterface {
            public function __construct(private array &$records) {}

            public function handle(LogRecord $record): void
            {
                $this->records[] = $record;
            }
        };

        $logger = new ChannelLogger('app', $handler);
        $logger->error('err');
        $logger->warning('warn');

        $this->assertCount(2, $records);
        $this->assertSame(LogLevel::ERROR, $records[0]->level);
        $this->assertSame(LogLevel::WARNING, $records[1]->level);
        $this->assertSame('app', $records[0]->channel);
        $this->assertSame('app', $records[1]->channel);
    }
}
