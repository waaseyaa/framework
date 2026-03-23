<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldItemBase;

#[FieldType(
    id: 'datetime',
    label: 'Date and Time',
    description: 'A field containing a date and time in ISO 8601 format.',
    category: 'datetime',
    defaultCardinality: 1,
)]
class DateTimeItem extends FieldItemBase
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
            'value' => ['type' => 'varchar', 'length' => 32],
        ];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string', 'format' => 'date-time'];
    }
}
