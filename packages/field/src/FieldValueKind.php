<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

/**
 * Transport-neutral shapes a field type exposes on authoring/read surfaces.
 *
 * Surface adapters map these semantic shapes to their own native types. The
 * enum deliberately does not name GraphQL, JSON Schema, SQL, or an Admin
 * widget, so downstream field-type plugins do not acquire an upper-layer
 * dependency when they declare how their values travel over a wire.
 *
 * @api
 */
enum FieldValueKind: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Float = 'float';
    case FormattedText = 'formatted_text';
    case EntityReference = 'entity_reference';
}
