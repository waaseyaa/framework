<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Concurrency;

/**
 * Storage-independent conflict signal for a stale aggregate mutation.
 *
 * Protocol adapters catch this public entity-layer type without depending on
 * a concrete storage engine. The current authority token is deliberately not
 * exposed through the exception.
 *
 * @api
 */
class EntityMutationConflictException extends \RuntimeException
{
    public readonly null $currentToken;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $entityTypeId,
        public readonly string $entityId,
        ?\Throwable $previous = null,
    ) {
        $this->currentToken = null;
        parent::__construct("Entity mutation conflict on {$entityTypeId} '{$entityId}'.", previous: $previous);
    }
}
