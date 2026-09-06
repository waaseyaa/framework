<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

/**
 * Two manifest sources declare the same `extra.waaseyaa.permissions` id.
 *
 * The compiled manifest's `permissions` inventory is keyed by id, so a silent
 * overwrite would let a later `installed.json` entry — or the root
 * application — replace another package's permission definition before the
 * kernel's permission catalogue ever sees it. Refusing at compile time names
 * both sources in installed order (the root application is merged last), so
 * the diagnostic identifies the existing owner and the conflicting source
 * deterministically (#2788).
 *
 * @internal Boot-integrity diagnostic for the package discovery compiler.
 */
final class PermissionManifestCollisionException extends \RuntimeException
{
    public function __construct(string $permissionId, string $existingOwner, string $conflictingOwner)
    {
        parent::__construct(sprintf(
            'PERMISSION_MANIFEST_COLLISION: permission "%s" is declared by both %s and %s; refusing to compile the package manifest. Retain exactly one owner.',
            $permissionId,
            $existingOwner,
            $conflictingOwner,
        ));
    }
}
