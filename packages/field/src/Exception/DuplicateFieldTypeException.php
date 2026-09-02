<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Exception;

/**
 * Two field-type plugins claim the same id.
 *
 * Raised while the registry resolves its definitions, so a downstream plugin
 * that shadows a built-in id (or another downstream plugin) refuses the boot
 * that would otherwise silently pick one of them.
 *
 * @api
 */
final class DuplicateFieldTypeException extends \LogicException
{
    public static function for(string $fieldType, string $registeredClass, string $conflictingClass): self
    {
        return new self(sprintf(
            'Field type "%s" is declared by both %s and %s; a field-type id must belong to exactly one plugin.',
            $fieldType,
            $registeredClass,
            $conflictingClass,
        ));
    }
}
