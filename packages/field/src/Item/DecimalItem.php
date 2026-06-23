<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'decimal',
    label: 'Decimal',
    description: 'A field containing a decimal number stored as a string for precision.',
    category: 'number',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class DecimalItem extends AbstractFieldType
{
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
