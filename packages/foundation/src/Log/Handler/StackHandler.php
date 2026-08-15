<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Handler;

use Waaseyaa\Foundation\Log\LogRecord;

final class StackHandler implements HandlerInterface
{
    /** @var list<HandlerInterface> */
    private readonly array $handlers;

    public function __construct(HandlerInterface ...$handlers)
    {
        $this->handlers = array_values($handlers);
    }

    public function handle(LogRecord $record): void
    {
        foreach ($this->handlers as $handler) {
            try {
                $handler->handle($record);
            } catch (\Throwable) {
                error_log('[log] LOG_HANDLER_FAILURE');
            }
        }
    }
}
