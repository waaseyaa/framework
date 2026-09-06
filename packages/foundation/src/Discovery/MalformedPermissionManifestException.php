<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

/**
 * A manifest source declares an `extra.waaseyaa.permissions` catalogue or
 * entry that does not satisfy {@see PermissionDefinitionShape}: a catalogue
 * that is not an object, an invalid id, or a malformed definition.
 *
 * Refusing at compile time names the owning source (an installed package
 * name or `root composer.json (extra.waaseyaa)`), so a malformed entry is
 * never silently skipped or coerced into the compiled catalogue the kernel
 * later trusts (#2788).
 *
 * @internal Boot-integrity diagnostic for the package discovery compiler.
 */
final class MalformedPermissionManifestException extends \RuntimeException
{
    public function __construct(string $detail)
    {
        parent::__construct(sprintf(
            'PERMISSION_MANIFEST_MALFORMED: %s Refusing to compile the package manifest.',
            $detail,
        ));
    }

    public static function catalogueNotAnObject(string $owner, mixed $catalogue): self
    {
        return new self(sprintf(
            '%s declares extra.waaseyaa.permissions that must be an object keyed by permission id; got %s.',
            $owner,
            get_debug_type($catalogue),
        ));
    }
}
