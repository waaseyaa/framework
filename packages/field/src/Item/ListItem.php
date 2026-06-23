<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'list',
    label: 'List (Select)',
    description: 'A field containing a value selected from a predefined list.',
    category: 'general',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class ListItem extends AbstractFieldType
{
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
