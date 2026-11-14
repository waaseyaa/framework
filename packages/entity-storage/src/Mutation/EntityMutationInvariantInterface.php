<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Mutation;

/** A mandatory invariant check performed before an entity mutation is persisted. */
interface EntityMutationInvariantInterface
{
    public function assertMutation(EntityMutationInvariantContext $context): void;
}
