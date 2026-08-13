<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Preview;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** @api */
interface RevisionPreviewGatewayInterface
{
    public function issue(
        AuthorizationPrincipalInterface $actor,
        string $entityId,
        int $expectedRevisionId,
    ): RevisionPreviewGrant;
}
