<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * A draft-mutation surface that can carry candidate-bound save advisory
 * acknowledgements.
 *
 * Redeclaring `updateDraft()` with one trailing optional parameter is legal
 * interface widening: implementors of the base contract keep loading, and only
 * implementors that opt into this sub-interface must accept the receipts.
 * Callers must never assume support — route through
 * {@see SaveAdvisoryAcknowledgementDispatcher::updateDraft()}, which fails
 * closed rather than dropping receipts.
 *
 * @internal Advisory-carrying extension of the governed authoring seam.
 */
interface AdvisoryAwareContentDraftMutationInterface extends ContentDraftMutationInterface
{
    /**
     * @param array<string, mixed> $values
     * @param list<string> $saveAdvisoryAcknowledgements Exact candidate-bound receipts.
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
