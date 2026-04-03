<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

final readonly class LogRecord
{
    public \DateTimeImmutable $timestamp;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public LogLevel $level,
        public string $message,
        public array $context = [],
        public string $channel = 'default',
        ?\DateTimeImmutable $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? new \DateTimeImmutable();
    }
}
