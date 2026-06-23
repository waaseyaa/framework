<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'image',
    label: 'Image',
    description: 'A field containing an image file reference with dimensions and alt text.',
    category: 'file',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class ImageItem extends AbstractFieldType
{
    public static function schema(): array
    {
        return [
            'uri' => ['type' => 'varchar', 'length' => 512],
            'filename' => ['type' => 'varchar', 'length' => 255],
            'mime_type' => ['type' => 'varchar', 'length' => 127],
            'size' => ['type' => 'int'],
            'alt' => ['type' => 'varchar', 'length' => 512],
            'width' => ['type' => 'int'],
            'height' => ['type' => 'int'],
        ];
    }

    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uri' => ['type' => 'string', 'maxLength' => 512],
                'filename' => ['type' => 'string', 'maxLength' => 255],
                'mime_type' => ['type' => 'string', 'maxLength' => 127],
                'size' => ['type' => 'integer'],
                'alt' => ['type' => 'string', 'maxLength' => 512],
                'width' => ['type' => 'integer'],
                'height' => ['type' => 'integer'],
            ],
            'required' => ['uri'],
        ];
    }
}
