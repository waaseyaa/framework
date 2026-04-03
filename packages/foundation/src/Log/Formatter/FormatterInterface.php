<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Formatter;

use Waaseyaa\Foundation\Log\LogRecord;

interface FormatterInterface
{
    public function format(LogRecord $record): string;
}
