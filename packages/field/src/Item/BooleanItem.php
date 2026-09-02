<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'boolean',
    label: 'Boolean',
    description: 'A field containing a boolean value.',
    category: 'general',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class BooleanItem extends AbstractFieldType implements \Waaseyaa\Field\FieldValueKindProviderInterface
{
    public static function valueKind(): \Waaseyaa\Field\FieldValueKind
    {
        return \Waaseyaa\Field\FieldValueKind::Boolean;
    }

    public static function schema(): array
    {
        return [
            // Preserve the production SqlSchemaHandler/ColumnSpecMap contract.
            // The former Field API descriptor used int/tiny, but it was not the
            // entity-storage authority before #2786.
            'value' => ['type' => 'boolean'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'boolean'];
    }
}
