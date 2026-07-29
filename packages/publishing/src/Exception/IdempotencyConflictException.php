<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Exception;

/** @api */
final class IdempotencyConflictException extends ContentPublishingException
{
    public function __construct(string $idempotencyKey)
    {
        parent::__construct(
            'IDEMPOTENCY_CONFLICT',
            sprintf('Idempotency key "%s" was already used with a different request payload.', $idempotencyKey),
        );
    }
}
