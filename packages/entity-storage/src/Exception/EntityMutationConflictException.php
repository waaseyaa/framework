<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

/** @api */
final class EntityMutationConflictException extends \RuntimeException
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
