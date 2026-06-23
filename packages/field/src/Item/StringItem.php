<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'string',
    label: 'String',
    description: 'A field containing a plain string value.',
    category: 'general',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class StringItem extends AbstractFieldType
{
    public static function schema(): array
    {
        return [
            'value' => ['type' => 'varchar', 'length' => 255],
        ];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string', 'maxLength' => 255];
    }
}
