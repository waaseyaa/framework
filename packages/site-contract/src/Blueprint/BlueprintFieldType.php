<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/**
 * The closed scalar field vocabulary a governed application blueprint may
 * declare on an entity (#2785, ADR-023).
 *
 * Case values are exactly the `#[FieldType(id: ...)]` ids under
 * `packages/field/src/Item/*.php` minus `entity_reference` (owned by
 * {@see BlueprintRelationship}, not a scalar field) and `file`/`image`
 * (media is out of the initial scope). `tests/Architecture/ApplicationBlueprintVocabularyTest.php`
 * proves this set stays a subset of the live field-type registry without
 * `waaseyaa/site-contract` (Layer 0) importing `waaseyaa/field` (Layer 1).
 *
 * @api
 */
enum BlueprintFieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Float = 'float';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Email = 'email';
    case Link = 'link';
    case Json = 'json';
    case Enum = 'enum';
    case ListSelect = 'list';
}
