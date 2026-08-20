<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** @internal Adapter seam for governed composite authoring services. */
interface ContentDraftMutationInterface
{
    /** @return array<string, mixed> */
    public function get(AuthorizationPrincipalInterface $actor, string $idOrSlug): array;

    /**
     * @param array<string, mixed> $values
     * @param list<string> $saveAdvisoryAcknowledgements
     * @return array<string, mixed>
     */
    public function updateDraft(
        AuthorizationPrincipalInterface $actor,
        string $id,
        array $values,
        int $expectedRevisionId,
        string $idempotencyKey,
        array $saveAdvisoryAcknowledgements = [],
    ): array;
}
