<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log\Processor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;
use Waaseyaa\Foundation\Log\Processor\RequestIdProcessor;

#[CoversClass(RequestIdProcessor::class)]
final class RequestIdProcessorTest extends TestCase
{
    #[Test]
    public function adds_request_id_to_context(): void
    {
        $processor = new RequestIdProcessor();
        $record = new LogRecord(LogLevel::INFO, 'test');

        $result = $processor->process($record);

        $this->assertArrayHasKey('request_id', $result->context);
        $this->assertNotEmpty($result->context['request_id']);
        $this->assertIsString($result->context['request_id']);
    }

    #[Test]
    public function same_id_within_request(): void
    {
        $processor = new RequestIdProcessor();
        $r1 = $processor->process(new LogRecord(LogLevel::INFO, 'first'));
        $r2 = $processor->process(new LogRecord(LogLevel::INFO, 'second'));

        $this->assertSame($r1->context['request_id'], $r2->context['request_id']);
    }

    #[Test]
    public function accepts_custom_request_id(): void
    {
        $processor = new RequestIdProcessor('custom-id-123');
        $result = $processor->process(new LogRecord(LogLevel::INFO, 'test'));

        $this->assertSame('custom-id-123', $result->context['request_id']);
    }

    #[Test]
    public function does_not_mutate_input(): void
    {
        $processor = new RequestIdProcessor();
        $original = new LogRecord(LogLevel::INFO, 'test', ['existing' => 'value']);

        $result = $processor->process($original);

        $this->assertArrayNotHasKey('request_id', $original->context);
        $this->assertArrayHasKey('request_id', $result->context);
        $this->assertSame('value', $result->context['existing']);
    }
}
