<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Draft;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * A layout-draft gateway that can carry candidate-bound save advisory
 * acknowledgements into the write.
 *
 * Redeclaring `update()` with one trailing optional parameter is legal
 * interface widening: implementors of {@see LayoutDraftGatewayInterface} keep
 * loading untouched, and only a gateway that opts into this sub-interface must
 * accept the receipts. Callers must never assume support — route through
 * {@see LayoutSaveAdvisoryAcknowledgementDispatcher::update()}, which fails
 * closed rather than dropping receipts.
 *
 * Opting in is the only supported way to carry receipts through the layout
 * seam: the base contract stays frozen, and this extension is itself frozen at
 * six parameters. A future capability gets its own extending interface.
 *
 * See docs/specs/save-advisories.md §11.
 *
 * @api
 */
interface AdvisoryAwareLayoutDraftGatewayInterface extends LayoutDraftGatewayInterface
{
    /**
     * @param list<string> $saveAdvisoryAcknowledgements Exact candidate-bound receipts.
     */
    public function update(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        string $encodedLayout,
        int $expectedRevisionId,
        string $idempotencyKey,
        array $saveAdvisoryAcknowledgements = [],
    ): LayoutDraftSnapshot;
}
