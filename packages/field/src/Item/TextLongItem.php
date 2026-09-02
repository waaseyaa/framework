<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;

/**
 * HTML-bearing long text exposed as a scalar entity value.
 *
 * This is intentionally distinct from TextItem's formatted two-column item:
 * existing sanitization, storage, GraphQL, SSR, and migration consumers use
 * the `text_long` id as a behavioural signal.
 *
 * @api
 */
#[FieldType(
    id: 'text_long',
    label: 'Long text',
    description: 'A field containing an HTML-bearing long text value.',
    category: 'general',
    defaultCardinality: 1,
)]
final class TextLongItem extends AbstractFieldType
{
    public static function schema(): array
    {
        return ['value' => ['type' => 'text']];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string'];
    }

    public static function supportsBlueprint(): bool
    {
        return false;
    }
}
