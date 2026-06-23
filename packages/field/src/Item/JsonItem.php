<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'json',
    label: 'JSON',
    description: 'A field containing a JSON-encoded value.',
    category: 'general',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class JsonItem extends AbstractFieldType
{
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
