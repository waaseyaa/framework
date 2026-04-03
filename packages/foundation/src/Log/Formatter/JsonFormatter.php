<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Formatter;

use Waaseyaa\Foundation\Log\LogRecord;

final class JsonFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        return json_encode([
            'timestamp' => $record->timestamp->format(\DateTimeInterface::ATOM),
            'level' => $record->level->value,
            'channel' => $record->channel,
            'message' => $record->message,
            'context' => $record->context,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
