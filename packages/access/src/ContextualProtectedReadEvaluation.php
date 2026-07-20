<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/** Invocation-local consistent-read context; never cache or retain it. */
final class ContextualProtectedReadEvaluation
{
    /** @var object{active: bool} Shared by clones so cleanup revokes every alias. */
    private readonly object $lease;

    private function __construct(
        public readonly object $authorizationBoundary,
        public readonly int $evaluatedAt,
        private readonly object $issuer,
    ) {
        $this->lease = new class {
            public bool $active = true;
        };
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Contextual read evaluations cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Contextual read evaluations cannot be unserialized.');
    }

    /** @internal */
    public function isActiveFor(object $issuer): bool
    {
        return $this->lease->active && $this->issuer === $issuer;
    }

    /** @internal */
    public function close(object $issuer): void
    {
        if ($this->issuer === $issuer) {
            $this->lease->active = false;
        }
    }
}
