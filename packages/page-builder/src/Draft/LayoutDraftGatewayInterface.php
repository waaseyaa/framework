<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Draft;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Application mutation boundary for a single revisionable layout field.
 *
 * Implementations must perform the update using the expected entity revision
 * as the authoritative write-time compare-and-swap claim.
 */
/** @api */
interface LayoutDraftGatewayInterface
{
    public function read(AuthorizationPrincipalInterface $actor, string $entityId): LayoutDraftSnapshot;

    public function update(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        string $encodedLayout,
        int $expectedRevisionId,
        string $idempotencyKey,
    ): LayoutDraftSnapshot;
}
