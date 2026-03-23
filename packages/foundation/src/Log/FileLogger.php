<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

final class FileLogger implements LoggerInterface
{
    use LoggerTrait;

    private const array LEVEL_PRIORITY = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7,
    ];

    public function __construct(
        private readonly string $filePath,
        private readonly LogLevel $minimumLevel = LogLevel::DEBUG,
    ) {}

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        if (self::LEVEL_PRIORITY[$level->value] > self::LEVEL_PRIORITY[$this->minimumLevel->value]) {
            return;
        }

        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $line = sprintf('[%s] [%s] %s', $timestamp, $level->value, (string) $message);

        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        file_put_contents($this->filePath, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
