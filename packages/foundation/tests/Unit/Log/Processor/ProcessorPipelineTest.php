<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log\Processor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\ChannelLogger;
use Waaseyaa\Foundation\Log\Handler\HandlerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;
use Waaseyaa\Foundation\Log\Processor\HostnameProcessor;
use Waaseyaa\Foundation\Log\Processor\ProcessorInterface;
use Waaseyaa\Foundation\Log\Processor\RequestIdProcessor;

#[CoversClass(ChannelLogger::class)]
final class ProcessorPipelineTest extends TestCase
{
    #[Test]
    public function processors_run_in_order(): void
    {
        $captured = null;
        $handler = new class ($captured) implements HandlerInterface {
            public function __construct(private ?LogRecord &$captured) {}

            public function handle(LogRecord $record): void
            {
                $this->captured = $record;
            }
        };

        $logger = new ChannelLogger('test', $handler, [
            new RequestIdProcessor('req-1'),
            new HostnameProcessor('web-01'),
        ]);

        $logger->info('pipeline test');

        $this->assertNotNull($captured);
        $this->assertSame('req-1', $captured->context['request_id']);
        $this->assertSame('web-01', $captured->context['hostname']);
    }

    #[Test]
    public function broken_processor_does_not_prevent_delivery(): void
    {
        $captured = null;
        $handler = new class ($captured) implements HandlerInterface {
            public function __construct(private ?LogRecord &$captured) {}

            public function handle(LogRecord $record): void
            {
                $this->captured = $record;
            }
        };

        $broken = new class implements ProcessorInterface {
            public function process(LogRecord $record): LogRecord
            {
                throw new \RuntimeException('processor boom');
            }
        };

        $logger = new ChannelLogger('test', $handler, [
            $broken,
            new HostnameProcessor('web-01'),
        ]);

        $logger->error('should survive');

        $this->assertNotNull($captured);
        $this->assertSame('should survive', $captured->message);
        $this->assertSame('web-01', $captured->context['hostname']);
    }

    #[Test]
    public function global_and_per_channel_processors_merge(): void
    {
        $captured = null;
        $handler = new class ($captured) implements HandlerInterface {
            public function __construct(private ?LogRecord &$captured) {}

            public function handle(LogRecord $record): void
            {
                $this->captured = $record;
            }
        };

        // Simulates global (request_id) + per-channel (hostname) merge.
        $logger = new ChannelLogger('app', $handler, [
            new RequestIdProcessor('global-req'),
            new HostnameProcessor('per-channel-host'),
        ]);

        $logger->info('merged');

        $this->assertNotNull($captured);
        $this->assertSame('global-req', $captured->context['request_id']);
        $this->assertSame('per-channel-host', $captured->context['hostname']);
        $this->assertSame('app', $captured->channel);
    }
}
