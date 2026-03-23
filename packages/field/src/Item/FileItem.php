<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldItemBase;

#[FieldType(
    id: 'file',
    label: 'File',
    description: 'A field containing a file reference with metadata.',
    category: 'file',
    defaultCardinality: 1,
)]
class FileItem extends FieldItemBase
{
    public static function propertyDefinitions(): array
    {
        return [
            'uri' => 'string',
            'filename' => 'string',
            'mime_type' => 'string',
            'size' => 'integer',
        ];
    }

    public static function mainPropertyName(): string
    {
        return 'uri';
    }

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
