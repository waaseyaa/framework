<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Formatter;

use Waaseyaa\Foundation\Log\LogRecord;

final class TextFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $line = sprintf(
            '[%s] [%s] [%s] %s',
            $record->timestamp->format(\DateTimeInterface::ATOM),
            $record->level->value,
            $record->channel,
            $record->message,
        );

        if ($record->context !== []) {
            $line .= ' ' . json_encode($record->context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return $line;
    }
}
