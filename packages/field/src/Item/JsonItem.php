<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldItemBase;

#[FieldType(
    id: 'json',
    label: 'JSON',
    description: 'A field containing a JSON-encoded value.',
    category: 'general',
    defaultCardinality: 1,
)]
class JsonItem extends FieldItemBase
{
    public static function propertyDefinitions(): array
    {
        return [
            'value' => 'string',
        ];
    }

    public static function mainPropertyName(): string
    {
        return 'value';
    }

    public static function schema(): array
    {
        return [
            'value' => ['type' => 'text'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'object'];
    }
}
