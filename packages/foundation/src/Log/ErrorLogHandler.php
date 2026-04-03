<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

final class ErrorLogHandler implements LoggerInterface
{
    use LoggerTrait;

    /** @var \Closure(string): void */
    private readonly \Closure $writer;

    /**
     * @param ?\Closure(string): void $writer Custom writer for testing. Defaults to error_log().
     */
    public function __construct(
        ?\Closure $writer = null,
        private readonly LogLevel $minimumLevel = LogLevel::DEBUG,
    ) {
        $this->writer = $writer ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        if ($level->severity() < $this->minimumLevel->severity()) {
            return;
        }

        $line = sprintf('[%s] %s', $level->value, (string) $message);

        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        ($this->writer)($line);
    }
}
