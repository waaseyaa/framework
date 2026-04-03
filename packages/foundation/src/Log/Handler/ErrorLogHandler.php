<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log\Handler;

use Waaseyaa\Foundation\Log\Formatter\FormatterInterface;
use Waaseyaa\Foundation\Log\Formatter\TextFormatter;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LogRecord;

final class ErrorLogHandler implements HandlerInterface
{
    private readonly FormatterInterface $formatter;

    /** @var \Closure(string): void */
    private readonly \Closure $writer;

    /**
     * @param ?\Closure(string): void $writer Custom writer for testing. Defaults to error_log().
     */
    public function __construct(
        ?FormatterInterface $formatter = null,
        private readonly LogLevel $minimumLevel = LogLevel::DEBUG,
        ?\Closure $writer = null,
    ) {
        $this->formatter = $formatter ?? new TextFormatter();
        $this->writer = $writer ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function handle(LogRecord $record): void
    {
        if ($record->level->severity() < $this->minimumLevel->severity()) {
            return;
        }

        ($this->writer)($this->formatter->format($record));
    }
}
