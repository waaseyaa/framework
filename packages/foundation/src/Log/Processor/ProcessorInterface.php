<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Processor;

use Waaseyaa\Foundation\Log\LogRecord;

interface ProcessorInterface
{
    /**
     * Enrich a log record with additional context.
     *
     * Must return a new LogRecord — must not mutate the input.
     */
    public function process(LogRecord $record): LogRecord;
}
