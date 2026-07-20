<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * A Protected entity-read policy whose decision requires one consistent
 * external authority snapshot for the complete candidate batch.
 *
 * @internal Framework-owned contextual policies only.
 */
interface ContextualProtectedEntityReadPolicyInterface extends ProtectedEntityReadPolicyInterface
{
    /** Stable invocation key used by a consumer to require this exact policy. */
    public function contextKey(): string;

    /** The exact authority boundary that must own the consistent-read transaction. */
    public function authorizationBoundary(): object;

    /**
     * @param list<ContextualProtectedReadCandidate> $candidates
     * @return array<array-key, mixed> Exactly one AccessResult keyed by candidate key.
     */
    public function accessBatch(
        AuthorizationPrincipalInterface $principal,
        array $candidates,
        ContextualProtectedReadEvaluation $evaluation,
        string $operation,
    ): array;
}
