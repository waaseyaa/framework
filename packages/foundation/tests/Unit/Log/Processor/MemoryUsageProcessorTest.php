<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log\Processor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;
use Waaseyaa\Foundation\Log\Processor\MemoryUsageProcessor;

#[CoversClass(MemoryUsageProcessor::class)]
final class MemoryUsageProcessorTest extends TestCase
{
    #[Test]
    public function adds_memory_peak_mb_to_context(): void
    {
        $processor = new MemoryUsageProcessor();
        $result = $processor->process(new LogRecord(LogLevel::INFO, 'test'));

        $this->assertArrayHasKey('memory_peak_mb', $result->context);
        $this->assertIsFloat($result->context['memory_peak_mb']);
        $this->assertGreaterThan(0.0, $result->context['memory_peak_mb']);
    }
}
