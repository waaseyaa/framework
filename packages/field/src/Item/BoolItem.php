<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldValueKind;
use Waaseyaa\Field\FieldValueKindProviderInterface;

/** Compatibility plugin for the historical `bool` field id. */
#[FieldType(id: 'bool', label: 'Boolean (legacy)', description: 'Legacy boolean field.', category: 'compatibility', defaultCardinality: 1)]
final class BoolItem extends AbstractFieldType implements FieldValueKindProviderInterface
{
    public static function valueKind(): FieldValueKind
    {
        return FieldValueKind::String;
    }

    public static function schema(): array
    {
        return ['value' => ['type' => 'boolean']];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string'];
    }

    public static function entityValueJsonSchemaFor(FieldDefinitionInterface $def): array
    {
        return ['type' => 'string'];
    }

    public static function supportsBlueprint(): bool
    {
        return false;
    }
}
