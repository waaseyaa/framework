<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

/**
 * Two discovered `#[FieldType]` plugins claim the same field-type id.
 *
 * The manifest's `field_types` inventory is keyed by id, so a silent overwrite
 * would drop one plugin from the roster the kernel admits fields against.
 * Refusing at compile time names both classes; the field package refuses the
 * same collision again when it resolves the registry.
 *
 * @internal Boot-integrity diagnostic for the package discovery compiler.
 */
final class FieldTypeManifestCollisionException extends \RuntimeException
{
    public function __construct(string $fieldType, string $registeredClass, string $conflictingClass)
    {
        parent::__construct(sprintf(
            'FIELD_TYPE_MANIFEST_COLLISION: field type "%s" is declared by both %s and %s; refusing to compile the package manifest.',
            $fieldType,
            $registeredClass,
            $conflictingClass,
        ));
    }
}
