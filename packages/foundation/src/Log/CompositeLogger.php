<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

final class CompositeLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<LoggerInterface> */
    private readonly array $loggers;

    public function __construct(LoggerInterface ...$loggers)
    {
        $this->loggers = array_values($loggers);
    }

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        foreach ($this->loggers as $logger) {
            try {
                $logger->log($level, $message, $context);
            } catch (\Throwable) {
                // Best-effort: one broken sink must not stop others.
            }
        }
    }
}
