<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Draft;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\PageBuilder\Draft\Exception\UnsupportedLayoutSaveAdvisoryAcknowledgementException;

/**
 * The one place that decides how a layout-draft update carries acknowledgements.
 *
 * Without receipts the ordinary five-argument
 * {@see LayoutDraftGatewayInterface::update()} is called, so a legacy gateway
 * keeps working unchanged. With receipts the gateway must implement
 * {@see AdvisoryAwareLayoutDraftGatewayInterface}; otherwise the call is
 * refused. Receipts are never discarded to make an update succeed.
 *
 * This mirrors `Waaseyaa\Publishing\SaveAdvisoryAcknowledgementDispatcher` one
 * seam further out: the publishing dispatcher governs the draft-mutation
 * contract, this one governs the layout-draft contract, and a
 * publishing-backed gateway routes through both.
 *
 * @api
 */
final class LayoutSaveAdvisoryAcknowledgementDispatcher
{
    private function __construct() {}

    /**
     * @param list<string> $saveAdvisoryAcknowledgements
     *
     * @throws UnsupportedLayoutSaveAdvisoryAcknowledgementException When receipts
     *         are supplied to a gateway that cannot carry them.
     */
    public static function update(
        LayoutDraftGatewayInterface $gateway,
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        string $encodedLayout,
        int $expectedRevisionId,
        string $idempotencyKey,
        array $saveAdvisoryAcknowledgements = [],
    ): LayoutDraftSnapshot {
        if ($saveAdvisoryAcknowledgements === []) {
            return $gateway->update($actor, $entityId, $encodedLayout, $expectedRevisionId, $idempotencyKey);
        }

        if (!$gateway instanceof AdvisoryAwareLayoutDraftGatewayInterface) {
            throw new UnsupportedLayoutSaveAdvisoryAcknowledgementException();
        }

        return $gateway->update(
            $actor,
            $entityId,
            $encodedLayout,
            $expectedRevisionId,
            $idempotencyKey,
            $saveAdvisoryAcknowledgements,
        );
    }
}
