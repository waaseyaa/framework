<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'datetime',
    label: 'Date and Time',
    description: 'A field containing a date and time in ISO 8601 format.',
    category: 'datetime',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class DateTimeItem extends AbstractFieldType
{
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
