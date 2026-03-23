<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldItemBase;

#[FieldType(
    id: 'date',
    label: 'Date',
    description: 'A field containing a date in YYYY-MM-DD format.',
    category: 'datetime',
    defaultCardinality: 1,
)]
class DateItem extends FieldItemBase
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
            'value' => ['type' => 'varchar', 'length' => 10],
        ];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string', 'format' => 'date'];
    }
}
