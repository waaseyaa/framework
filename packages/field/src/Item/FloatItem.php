<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'float',
    label: 'Float',
    description: 'A field containing a floating-point number.',
    category: 'general',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class FloatItem extends AbstractFieldType
{
    public static function schema(): array
    {
        return [
            'value' => ['type' => 'float'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'number'];
    }
}
