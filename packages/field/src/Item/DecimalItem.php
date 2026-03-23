<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldItemBase;

#[FieldType(
    id: 'decimal',
    label: 'Decimal',
    description: 'A field containing a decimal number stored as a string for precision.',
    category: 'number',
    defaultCardinality: 1,
)]
class DecimalItem extends FieldItemBase
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
            'value' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2],
        ];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string', 'pattern' => '^-?\\d+\\.\\d+$'];
    }

    public static function defaultSettings(): array
    {
        return ['precision' => 10, 'scale' => 2];
    }
}
