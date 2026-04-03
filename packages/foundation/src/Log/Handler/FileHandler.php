<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Handler;

use Waaseyaa\Foundation\Log\Formatter\FormatterInterface;
use Waaseyaa\Foundation\Log\Formatter\TextFormatter;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

final class FileHandler implements HandlerInterface
{
    private readonly FormatterInterface $formatter;

    public function __construct(
        private readonly string $filePath,
        ?FormatterInterface $formatter = null,
        private readonly LogLevel $minimumLevel = LogLevel::DEBUG,
    ) {
        $this->formatter = $formatter ?? new TextFormatter();
    }

    public function handle(LogRecord $record): void
    {
        if ($record->level->severity() < $this->minimumLevel->severity()) {
            return;
        }

        file_put_contents($this->filePath, $this->formatter->format($record) . "\n", FILE_APPEND | LOCK_EX);
    }
}
