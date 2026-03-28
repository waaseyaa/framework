<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

enum LogLevel: string
{
    case EMERGENCY = 'emergency';
    case ALERT = 'alert';
    case CRITICAL = 'critical';
    case ERROR = 'error';
    case WARNING = 'warning';
    case NOTICE = 'notice';
    case INFO = 'info';
    case DEBUG = 'debug';

    public function severity(): int
    {
        return match ($this) {
            self::DEBUG => 0,
            self::INFO => 1,
            self::NOTICE => 2,
            self::WARNING => 3,
            self::ERROR => 4,
            self::CRITICAL => 5,
            self::ALERT => 6,
            self::EMERGENCY => 7,
        };
    }

    public static function fromName(string $name): ?self
    {
        return self::tryFrom(strtolower($name));
    }
}
