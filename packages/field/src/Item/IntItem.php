<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldValueKind;
use Waaseyaa\Field\FieldValueKindProviderInterface;

/** Compatibility plugin for the historical `int` field id. */
#[FieldType(id: 'int', label: 'Integer (legacy)', description: 'Legacy integer field.', category: 'compatibility', defaultCardinality: 1)]
final class IntItem extends AbstractFieldType implements FieldValueKindProviderInterface
{
    public static function valueKind(): FieldValueKind
    {
        return FieldValueKind::String;
    }

    public static function schema(): array
    {
        return ['value' => ['type' => 'int']];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string'];
    }

    public static function entityValueJsonSchemaFor(FieldDefinitionInterface $def): array
    {
        return ['type' => 'string'];
    }

    public static function supportsBlueprint(): bool
    {
        return false;
    }
}
