<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log\Processor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;
use Waaseyaa\Foundation\Log\Processor\HostnameProcessor;

#[CoversClass(HostnameProcessor::class)]
final class HostnameProcessorTest extends TestCase
{
    #[Test]
    public function adds_hostname_to_context(): void
    {
        $processor = new HostnameProcessor();
        $result = $processor->process(new LogRecord(LogLevel::INFO, 'test'));

        $this->assertArrayHasKey('hostname', $result->context);
        $this->assertSame(gethostname() ?: 'unknown', $result->context['hostname']);
    }

    #[Test]
    public function accepts_custom_hostname(): void
    {
        $processor = new HostnameProcessor('web-01');
        $result = $processor->process(new LogRecord(LogLevel::INFO, 'test'));

        $this->assertSame('web-01', $result->context['hostname']);
    }
}
