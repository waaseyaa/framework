<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Mutation;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Immutable information needed by an entity mutation invariant.
 *
 * @api Context supplied to package-owned mandatory mutation guards.
 */
final readonly class EntityMutationInvariantContext
{
    public function __construct(
        public EntityMutationOperation $operation,
        public ?EntityInterface $original,
        public ?EntityInterface $proposed,
        public ?AuthorizationPrincipalInterface $principal,
        public DatabaseInterface $database,
        public string $transactionIdentity,
    ) {
        if (trim($transactionIdentity) === '') {
            throw new \InvalidArgumentException('Transaction identity must not be blank.');
        }
    }
}
