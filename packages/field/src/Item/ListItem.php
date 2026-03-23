<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldItemBase;

#[FieldType(
    id: 'list',
    label: 'List (Select)',
    description: 'A field containing a value selected from a predefined list.',
    category: 'general',
    defaultCardinality: 1,
)]
class ListItem extends FieldItemBase
{
    public static function propertyDefinitions(): array
    {
        return [
            'value' => 'string',
        ];
    }

    public static function mainPropertyName(): string
    {
        return 'value';
    }

    public static function schema(): array
    {
        return [
            'value' => ['type' => 'varchar', 'length' => 255],
        ];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string'];
    }

    public static function defaultSettings(): array
    {
        return ['allowed_values' => []];
    }
}
