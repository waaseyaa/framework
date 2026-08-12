<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Waaseyaa\Entity\EntityInterface;

/**
 * Atomic aggregate command boundary for multi-write domain mutations.
 *
 * The mutation callback runs after the repository has won the aggregate CAS
 * and inside the same transaction as the entity/revision write. When
 * `$publishRevision` is true, the newly created revision and both publication
 * pointers commit with that same claim. Any callback, guard, storage, or
 * pointer failure rolls back the claim and every write and emits no buffered
 * post-commit event.
 *
 * @internal Domain services with a genuine multi-write aggregate command only.
 */
interface AggregateMutationRepositoryInterface
{
    /**
     * @param \Closure(EntityInterface): void $mutation
     * @param null|\Closure(EntityInterface): void $publicationFinalizer
     * @param null|\Closure(EntityInterface): void $beforeCommit
     */
    public function saveAggregateMutation(
        EntityInterface $entity,
        \Closure $mutation,
        bool $publishRevision = false,
        bool $validate = true,
        ?\Closure $publicationFinalizer = null,
        ?\Closure $beforeCommit = null,
    ): EntityInterface;
}
