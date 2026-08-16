<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** @internal Adapter seam for access-checked revision history and copy-forward restore. */
interface ContentRevisionHistoryInterface
{
    /** @return list<array<string, mixed>> */
    public function revisions(AuthorizationPrincipalInterface $actor, string $id): array;

    /** @return array<string, mixed> */
    public function revision(AuthorizationPrincipalInterface $actor, string $id, int $revisionId): array;

    /** @return array<string, mixed> */
    public function rollback(
        AuthorizationPrincipalInterface $actor,
        string $id,
        int $targetRevisionId,
        string $idempotencyKey,
        string $note = '',
        ?int $expectedCurrentRevisionId = null,
    ): array;
}
