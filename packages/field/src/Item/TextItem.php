<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'text',
    label: 'Text',
    description: 'A field containing formatted text with a text format.',
    category: 'general',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class TextItem extends AbstractFieldType
{
    public static function schema(): array
    {
        return [
            'value' => ['type' => 'text'],
            'format' => ['type' => 'varchar', 'length' => 255],
        ];
    }

    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
                'format' => ['type' => 'string'],
            ],
        ];
    }
}
