<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'file',
    label: 'File',
    description: 'A field containing a file reference with metadata.',
    category: 'file',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class FileItem extends AbstractFieldType
{
    public static function schema(): array
    {
        return [
            'uri' => ['type' => 'varchar', 'length' => 512],
            'filename' => ['type' => 'varchar', 'length' => 255],
            'mime_type' => ['type' => 'varchar', 'length' => 127],
            'size' => ['type' => 'int'],
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
            ],
            'required' => ['uri'],
        ];
    }
}
