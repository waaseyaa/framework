<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Adapter seam for governed composite authoring services.
 *
 * This signature is frozen: applications implement it directly (page-builder
 * gateways, id-resolving decorators), so adding a parameter here — even an
 * optional one — is a load-time fatal for every existing implementor. Save
 * advisory acknowledgements therefore live on
 * {@see AdvisoryAwareContentDraftMutationInterface}, which extends this
 * contract instead of altering it.
 *
 * @internal Adapter seam for governed composite authoring services.
 */
interface ContentDraftMutationInterface
{
    /** @return array<string, mixed> */
    public function get(AuthorizationPrincipalInterface $actor, string $idOrSlug): array;

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function updateDraft(
        AuthorizationPrincipalInterface $actor,
        string $id,
        array $values,
        int $expectedRevisionId,
        string $idempotencyKey,
    ): array;
}
