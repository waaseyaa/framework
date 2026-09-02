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
final class JsonItem extends AbstractFieldType implements \Waaseyaa\Field\FieldValueKindProviderInterface
{
    public static function valueKind(): \Waaseyaa\Field\FieldValueKind
    {
        return \Waaseyaa\Field\FieldValueKind::String;
    }

    public static function entityValueJsonSchemaFor(\Waaseyaa\Field\FieldDefinitionInterface $def): array
    {
        return ['type' => ['object', 'array', 'string', 'number', 'boolean', 'null']];
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
