<?php

declare(strict_types=1);

namespace Aurora\Foundation\Exception;

final class AuthenticationException extends AuroraException
{
    public function __construct(
        string $message,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'aurora:auth/error', 401, $context, $previous);
    }
}
