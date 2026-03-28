<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final class OAuthException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly int $httpStatusCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
