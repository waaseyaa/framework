<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

final class LogManager implements LoggerInterface
{
    use LoggerTrait;

    /** @var array<string, LoggerInterface> */
    private array $channels = [];

    public function __construct(LoggerInterface $defaultChannel)
    {
        $this->channels['default'] = $defaultChannel;
    }

    /**
     * Return a logger for the given channel name.
     *
     * Unknown channels fall back to the default channel.
     */
    public function channel(string $name): LoggerInterface
    {
        return $this->channels[$name] ?? $this->channels['default'];
    }

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $this->channels['default']->log($level, $message, $context);
    }
}
