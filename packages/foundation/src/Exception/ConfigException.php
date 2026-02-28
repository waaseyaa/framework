<?php

declare(strict_types=1);

namespace Aurora\Foundation\Exception;

final class ConfigException extends AuroraException
{
    public function __construct(
        string $message,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'aurora:config/error', 500, $context, $previous);
    }
}
