<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Exception;

/** @api */
final class MissingEntityMutationTokenException extends \RuntimeException
{
    public function __construct(string $entityTypeId, string $entityId)
    {
        parent::__construct("Existing entity mutation requires its loaded snapshot token: {$entityTypeId} '{$entityId}'.");
    }
}
