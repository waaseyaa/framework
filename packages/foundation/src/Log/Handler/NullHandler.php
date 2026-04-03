<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Handler;

use Waaseyaa\Foundation\Log\LogRecord;

final class NullHandler implements HandlerInterface
{
    public function handle(LogRecord $record): void
    {
        // Intentionally discards all records.
    }
}
