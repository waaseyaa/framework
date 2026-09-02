<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'integer',
    label: 'Integer',
    description: 'A field containing an integer value.',
    category: 'general',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class IntegerItem extends AbstractFieldType implements \Waaseyaa\Field\FieldValueKindProviderInterface
{
    public static function valueKind(): \Waaseyaa\Field\FieldValueKind
    {
        return \Waaseyaa\Field\FieldValueKind::Integer;
    }

    public static function entityValueJsonSchemaFor(\Waaseyaa\Field\FieldDefinitionInterface $def): array
    {
        if ($def->getSetting('subtype') === 'timestamp') {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        return static::jsonSchema();
    }

    public static function schema(): array
    {
        return [
            'value' => ['type' => 'int'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'integer'];
    }
}
