<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Application authority for an exact saved generic-entity revision preview.
 *
 * The Admin package proves record/revision existence and view access before
 * invoking this seam. Applications own signing, expiry, audience binding, and
 * the route that renders the grant.
 *
 * @api
 */
interface AdminRevisionPreviewAuthorityInterface
{
    public function issue(
        AccountInterface $actor,
        EntityInterface $revision,
        int $revisionId,
    ): ?AdminRevisionPreviewGrantData;
}
