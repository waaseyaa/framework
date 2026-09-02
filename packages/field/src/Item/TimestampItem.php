<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldValueKind;
use Waaseyaa\Field\FieldValueKindProviderInterface;

/** Compatibility plugin preserving Unix-domain/text-storage timestamps. */
#[FieldType(id: 'timestamp', label: 'Timestamp (legacy)', description: 'Legacy Unix timestamp field.', category: 'compatibility', defaultCardinality: 1)]
final class TimestampItem extends AbstractFieldType implements FieldValueKindProviderInterface
{
    public static function valueKind(): FieldValueKind
    {
        return FieldValueKind::String;
    }

    public static function schema(): array
    {
        return ['value' => ['type' => 'text']];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string'];
    }

    public static function entityValueJsonSchemaFor(FieldDefinitionInterface $def): array
    {
        return ['type' => 'string', 'format' => 'date-time'];
    }

    public static function supportsBlueprint(): bool
    {
        return false;
    }
}
