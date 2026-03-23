<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldItemBase;

#[FieldType(
    id: 'email',
    label: 'Email',
    description: 'A field containing an email address.',
    category: 'general',
    defaultCardinality: 1,
)]
class EmailItem extends FieldItemBase
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
            'value' => ['type' => 'varchar', 'length' => 254],
        ];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string', 'format' => 'email', 'maxLength' => 254];
    }
}
