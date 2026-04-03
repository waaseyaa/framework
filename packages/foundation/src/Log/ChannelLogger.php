<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

use Waaseyaa\Foundation\Log\Handler\HandlerInterface;
use Waaseyaa\Foundation\Log\Processor\ProcessorInterface;

final class ChannelLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<ProcessorInterface> */
    private readonly array $processors;

    /**
     * @param list<ProcessorInterface> $processors Global + per-channel processors (run in order).
     */
    public function __construct(
        private readonly string $channel,
        private readonly HandlerInterface $handler,
        array $processors = [],
    ) {
        $this->processors = array_values($processors);
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
            } catch (\Throwable $e) {
                error_log(sprintf('[log] Processor %s failed: %s', $processor::class, $e->getMessage()));
            }
        }

        $this->handler->handle($record);
    }
}
