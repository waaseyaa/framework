<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'email',
    label: 'Email',
    description: 'A field containing an email address.',
    category: 'general',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class EmailItem extends AbstractFieldType
{
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
