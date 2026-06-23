<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'date',
    label: 'Date',
    description: 'A field containing a date in YYYY-MM-DD format.',
    category: 'datetime',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class DateItem extends AbstractFieldType
{
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
