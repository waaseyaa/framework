<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Mutation;

/** Registry of required mutation invariants keyed by their service/guard ID. */
final class MutationInvariantRegistry
{
    /** @var array<string, EntityMutationInvariantInterface> */
    private array $invariants = [];

    public function register(string $id, EntityMutationInvariantInterface $invariant): void
    {
        if (trim($id) === '') {
            throw new \InvalidArgumentException('Mutation invariant ID must not be blank.');
        }

        if (isset($this->invariants[$id])) {
            throw new \LogicException("Mutation invariant is already registered: {$id}");
        }

        $this->invariants[$id] = $invariant;
    }

    public function get(string $id): EntityMutationInvariantInterface
    {
        return $this->invariants[$id]
            ?? throw new \OutOfBoundsException("Unknown mutation invariant: {$id}");
    }
}
