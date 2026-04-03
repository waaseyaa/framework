<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log\Processor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;
use Waaseyaa\Foundation\Log\Processor\RequestContextProcessor;

#[CoversClass(RequestContextProcessor::class)]
final class RequestContextProcessorTest extends TestCase
{
    #[Test]
    public function adds_method_and_uri_to_context(): void
    {
        $processor = new RequestContextProcessor('GET', '/api/nodes');
        $record = new LogRecord(LogLevel::INFO, 'test');

        $result = $processor->process($record);

        $this->assertSame('GET', $result->context['http_method']);
        $this->assertSame('/api/nodes', $result->context['uri']);
    }

    #[Test]
    public function adds_request_id_when_provided(): void
    {
        $processor = new RequestContextProcessor('POST', '/api/users', 'req-abc-123');
        $result = $processor->process(new LogRecord(LogLevel::INFO, 'test'));

        $this->assertSame('req-abc-123', $result->context['request_id']);
        $this->assertSame('POST', $result->context['http_method']);
        $this->assertSame('/api/users', $result->context['uri']);
    }

    #[Test]
    public function omits_request_id_when_not_provided(): void
    {
        $processor = new RequestContextProcessor('DELETE', '/api/nodes/5');
        $result = $processor->process(new LogRecord(LogLevel::INFO, 'test'));

        $this->assertArrayNotHasKey('request_id', $result->context);
        $this->assertSame('DELETE', $result->context['http_method']);
    }

    #[Test]
    public function does_not_mutate_input(): void
    {
        $processor = new RequestContextProcessor('GET', '/');
        $original = new LogRecord(LogLevel::INFO, 'test', ['existing' => 'value']);

        $result = $processor->process($original);

        $this->assertArrayNotHasKey('http_method', $original->context);
        $this->assertArrayNotHasKey('uri', $original->context);
        $this->assertSame('value', $result->context['existing']);
        $this->assertSame('GET', $result->context['http_method']);
    }

    #[Test]
    public function preserves_all_record_fields(): void
    {
        $processor = new RequestContextProcessor('PUT', '/api/config');
        $original = new LogRecord(
            level: LogLevel::ERROR,
            message: 'Something failed',
            context: ['detail' => 'info'],
            channel: 'app',
        );

        $result = $processor->process($original);

        $this->assertSame(LogLevel::ERROR, $result->level);
        $this->assertSame('Something failed', $result->message);
        $this->assertSame('app', $result->channel);
        $this->assertSame($original->timestamp, $result->timestamp);
        $this->assertSame('info', $result->context['detail']);
    }

    #[Test]
    public function same_context_across_multiple_records(): void
    {
        $processor = new RequestContextProcessor('GET', '/api/nodes');
        $r1 = $processor->process(new LogRecord(LogLevel::INFO, 'first'));
        $r2 = $processor->process(new LogRecord(LogLevel::INFO, 'second'));

        $this->assertSame($r1->context['http_method'], $r2->context['http_method']);
        $this->assertSame($r1->context['uri'], $r2->context['uri']);
    }
}
