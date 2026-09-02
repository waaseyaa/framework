<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Exception;

use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldTypeInterface;

/**
 * A class offered to the field-type registry cannot be admitted as a plugin.
 *
 * The package manifest hands the registry class names it discovered at compile
 * time; each must still be a loadable, concrete `#[FieldType]`-annotated
 * `FieldTypeInterface` implementation when the registry resolves it, or the
 * registry has no id and no static schema seam to admit it under.
 *
 * @api
 */
final class InvalidFieldTypePluginException extends \LogicException
{
    public static function missingClass(string $class): self
    {
        return new self(sprintf(
            'Field-type plugin %s cannot be loaded; run `php bin/waaseyaa optimize:manifest` after removing or renaming a plugin.',
            $class,
        ));
    }

    public static function missingAttribute(string $class): self
    {
        return new self(sprintf(
            'Field-type plugin %s carries no #[%s] attribute, so it has no id to be registered under.',
            $class,
            FieldType::class,
        ));
    }

    public static function notAFieldType(string $class): self
    {
        return new self(sprintf(
            'Field-type plugin %s must be a concrete %s implementation (extend %s).',
            $class,
            FieldTypeInterface::class,
            \Waaseyaa\Field\AbstractFieldType::class,
        ));
    }

    public static function idMismatch(string $manifestId, string $attributeId, string $class): self
    {
        return new self(sprintf(
            'Field-type plugin %s is cached under manifest id "%s" but its #[%s] attribute declares "%s"; rebuild the package manifest.',
            $class,
            $manifestId,
            FieldType::class,
            $attributeId,
        ));
    }
}
