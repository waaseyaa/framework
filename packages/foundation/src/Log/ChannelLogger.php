<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

use Waaseyaa\Foundation\Log\Handler\HandlerInterface;
use Waaseyaa\Foundation\Log\Processor\ProcessorInterface;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;

final class ChannelLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<ProcessorInterface> */
    private readonly array $processors;

    private readonly RedactorProcessor $sinkSanitizer;

    /**
     * @param list<ProcessorInterface> $processors Global + per-channel processors (run in order).
     */
    public function __construct(
        private readonly string $channel,
        private readonly HandlerInterface $handler,
        array $processors = [],
        ?RedactorProcessor $sinkSanitizer = null,
    ) {
        $this->processors = array_values($processors);
        $this->sinkSanitizer = $sinkSanitizer ?? new RedactorProcessor();
    }

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $record = new LogRecord(
            level: $level,
            message: (string) $message,
            context: $context,
            channel: $this->channel,
        );

        foreach ($this->processors as $processor) {
            try {
                $record = $processor->process($record);
            } catch (\Throwable) {
                error_log('[log] LOG_PROCESSOR_FAILURE');
            }
        }

        try {
            $record = $this->sinkSanitizer->process($record);
        } catch (\Throwable) {
            error_log('[log] LOG_SANITIZER_FAILURE');

            return;
        }

        $this->handler->handle($record);
    }
}
