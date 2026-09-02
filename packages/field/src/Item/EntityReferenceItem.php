<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

#[FieldType(
    id: 'entity_reference',
    label: 'Entity Reference',
    description: 'A field containing a reference to another entity.',
    category: 'reference',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class EntityReferenceItem extends AbstractFieldType implements \Waaseyaa\Field\FieldValueKindProviderInterface
{
    public static function valueKind(): \Waaseyaa\Field\FieldValueKind
    {
        return \Waaseyaa\Field\FieldValueKind::EntityReference;
    }

    public static function entityStorageColumnSchemaFor(
        \Waaseyaa\Field\FieldDefinitionInterface $def,
        ?\Waaseyaa\Field\FieldStorageSchemaContext $context = null,
    ): array {
        return ['type' => 'varchar', 'length' => (int) ($def->getSetting('length') ?? 255)];
    }

    public static function supportsBlueprint(): bool
    {
        return false;
    }

    public static function entityValueJsonSchemaFor(\Waaseyaa\Field\FieldDefinitionInterface $def): array
    {
        return ['type' => 'string'];
    }

    public static function schema(): array
    {
        return [
            'target_id' => ['type' => 'int'],
            'target_type' => ['type' => 'varchar', 'length' => 255],
        ];
    }

    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_id' => ['type' => 'integer'],
                'target_type' => ['type' => 'string'],
            ],
        ];
    }
}
