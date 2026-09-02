<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldStorageSchemaContext;
use Waaseyaa\Field\FieldValueKind;
use Waaseyaa\Field\FieldValueKindProviderInterface;

/** Compatibility plugin for the historical `uri` field id. */
#[FieldType(id: 'uri', label: 'URI (legacy)', description: 'Legacy URI field.', category: 'compatibility', defaultCardinality: 1)]
final class UriItem extends AbstractFieldType implements FieldValueKindProviderInterface
{
    public static function valueKind(): FieldValueKind
    {
        return FieldValueKind::String;
    }

    public static function schema(): array
    {
        return ['value' => ['type' => 'varchar', 'length' => 2048]];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string', 'format' => 'uri'];
    }

    public static function entityValueJsonSchemaFor(FieldDefinitionInterface $def): array
    {
        return ['type' => 'string', 'format' => 'uri'];
    }

    public static function entityStorageColumnSchemaFor(
        FieldDefinitionInterface $def,
        ?FieldStorageSchemaContext $context = null,
    ): array {
        if ($context === FieldStorageSchemaContext::ColumnSpecMap) {
            return ['type' => 'text'];
        }

        $settings = $def->getSettings();

        return [
            'type' => 'varchar',
            'length' => isset($settings['length']) ? (int) $settings['length'] : 2048,
        ];
    }

    public static function supportsBlueprint(): bool
    {
        return false;
    }
}
