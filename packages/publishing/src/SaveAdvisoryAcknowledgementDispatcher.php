<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Publishing\Exception\UnsupportedSaveAdvisoryAcknowledgementException;

/**
 * The one place that decides how a draft update carries acknowledgements.
 *
 * Without receipts the ordinary five-argument
 * {@see ContentDraftMutationInterface::updateDraft()} is called, so a legacy
 * implementor keeps working unchanged. With receipts the surface must
 * implement {@see AdvisoryAwareContentDraftMutationInterface}; otherwise the
 * call is refused. Receipts are never discarded to make a call succeed.
 *
 * @api
 */
final class SaveAdvisoryAcknowledgementDispatcher
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $values
     * @param list<string> $saveAdvisoryAcknowledgements
     * @return array<string, mixed>
     *
     * @throws UnsupportedSaveAdvisoryAcknowledgementException When receipts are
     *         supplied to a surface that cannot carry them.
     */
    public static function updateDraft(
        ContentDraftMutationInterface $mutation,
        AuthorizationPrincipalInterface $actor,
        string $id,
        array $values,
        int $expectedRevisionId,
        string $idempotencyKey,
        array $saveAdvisoryAcknowledgements = [],
    ): array {
        if ($saveAdvisoryAcknowledgements === []) {
            return $mutation->updateDraft($actor, $id, $values, $expectedRevisionId, $idempotencyKey);
        }

        if (!$mutation instanceof AdvisoryAwareContentDraftMutationInterface) {
            throw new UnsupportedSaveAdvisoryAcknowledgementException();
        }

        return $mutation->updateDraft(
            $actor,
            $id,
            $values,
            $expectedRevisionId,
            $idempotencyKey,
            $saveAdvisoryAcknowledgements,
        );
    }
}
