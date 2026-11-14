<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

/** @api Domain refusal details for HTTP and non-HTTP engagement callers. */
final class EngagementException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
