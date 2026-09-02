<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldValueKind;
use Waaseyaa\Field\FieldValueKindProviderInterface;

/** Compatibility plugin for the historical `list_string` field id. */
#[FieldType(id: 'list_string', label: 'String list (legacy)', description: 'Legacy string selection field.', category: 'compatibility', defaultCardinality: 1)]
final class ListStringItem extends AbstractFieldType implements FieldValueKindProviderInterface
{
    public static function valueKind(): FieldValueKind
    {
        return FieldValueKind::String;
    }

    public static function schema(): array
    {
        return ['value' => ['type' => 'text']];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string'];
    }

    public static function entityValueJsonSchemaFor(FieldDefinitionInterface $def): array
    {
        return ['type' => 'string'];
    }

    public static function defaultSettings(): array
    {
        return ['allowed_values' => []];
    }

    public static function supportsBlueprint(): bool
    {
        return false;
    }
}
