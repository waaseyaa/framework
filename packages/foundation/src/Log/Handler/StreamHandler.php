<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Handler;

use Waaseyaa\Foundation\Log\Formatter\FormatterInterface;
use Waaseyaa\Foundation\Log\Formatter\TextFormatter;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

final class StreamHandler implements HandlerInterface
{
    private readonly FormatterInterface $formatter;

    /**
     * @param resource $stream
     */
    public function __construct(
        private $stream,
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

        fwrite($this->stream, $this->formatter->format($record) . "\n");
    }
}
