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
    public function broken_processor_emits_only_a_fixed_non_sensitive_code(): void
    {
        $logFile = sys_get_temp_dir() . '/waaseyaa-cfg04-processor-' . bin2hex(random_bytes(6)) . '.log';
        $previousLog = ini_set('error_log', $logFile);

        try {
            $handler = new class implements HandlerInterface {
                public function handle(LogRecord $record): void {}
            };
            $logger = new ChannelLogger('test', $handler, [
                new class implements ProcessorInterface {
                    public function process(LogRecord $record): LogRecord
                    {
                        throw new \RuntimeException('cfg04-processor-exception-canary');
                    }
                },
            ]);

            $logger->error('safe record');

            $output = file_get_contents($logFile);
            $this->assertIsString($output);
            $this->assertStringContainsString('LOG_PROCESSOR_FAILURE', $output);
            $this->assertStringNotContainsString('cfg04-processor-exception-canary', $output);
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
