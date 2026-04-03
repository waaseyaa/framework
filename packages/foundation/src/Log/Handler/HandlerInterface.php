<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Handler;

use Waaseyaa\Foundation\Log\LogRecord;

interface HandlerInterface
{
    public function handle(LogRecord $record): void;
}
