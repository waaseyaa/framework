<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Revision;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\PageBuilder\Draft\LayoutDraftSnapshot;

/** Application boundary for governed page history and restore-as-new-draft. @api */
interface PageBuilderRevisionGatewayInterface
{
    /** @return list<PageBuilderRevisionSnapshot> Newest first. */
    public function list(AuthorizationPrincipalInterface $actor, string $entityId): array;

    public function readRevision(AuthorizationPrincipalInterface $actor, string $entityId, int $revisionId): PageBuilderRevisionSnapshot;

    public function restore(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $targetRevisionId,
        int $expectedCurrentRevisionId,
        string $idempotencyKey,
    ): LayoutDraftSnapshot;
}
