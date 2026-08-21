<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Adapter seam for governed composite authoring services.
 *
 * Applications implement this contract directly — page-builder gateways and
 * id-resolving decorators — and {@see SaveAdvisoryAcknowledgementDispatcher}
 * accepts it as a parameter, so it is a supported public extension point.
 *
 * **The five-parameter `updateDraft()` signature is frozen.** PHP checks an
 * implementing method against every parameter its interface declares, so adding
 * a parameter here — even a trailing optional one — is a load-time fatal for
 * every existing implementor. Save advisory acknowledgements therefore live on
 * {@see AdvisoryAwareContentDraftMutationInterface}, which extends this contract
 * instead of altering it. Future capability arrives the same way: a further
 * extending interface or a value object, never a parameter added here.
 *
 * See docs/specs/save-advisories.md §10 for the full compatibility promise.
 *
 * @api
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
