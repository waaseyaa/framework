<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'link',
    label: 'Link',
    description: 'A field containing a URI with an optional title.',
    category: 'general',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class LinkItem extends AbstractFieldType
{
    public static function schema(): array
    {
        return [
            'uri' => ['type' => 'varchar', 'length' => 2048],
            'title' => ['type' => 'varchar', 'length' => 255],
        ];
    }

    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uri' => ['type' => 'string', 'maxLength' => 2048],
                'title' => ['type' => 'string', 'maxLength' => 255],
            ],
            'required' => ['uri'],
        ];
    }
}
