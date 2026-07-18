<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Attribute;

use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldStorage;

/**
 * Marks a public typed property of a content entity class as a persistable field.
 *
 * When `type:` is null, the field type id is inferred from the PHP property type
 * by {@see FieldTypeInferrer}. When `type:` is set explicitly it must be one of
 * the registered field-type ids (and must be compatible with the declared PHP
 * property type, when one is present).
 *
 * Backed-enum properties infer canonically as `type='enum'` with
 * `settings.enum_class` populated automatically. The legacy
 * `'string' + settings.enum_class` bridge is no longer supported: explicitly
 * passing `type: 'string'` on a backed-enum property is rejected.
 *
 * `stored:` selects how the field is persisted: {@see FieldStorage::Column}
 * (default) materializes a dedicated SQL column; {@see FieldStorage::Data}
 * keeps the value in the entity's `_data` JSON blob and routes queries via
 * `json_extract`. Forwarded verbatim to `FieldDefinition` by
 * {@see EntityMetadataReader::resolveFields()}.
 *
 * Example:
 *
 *     #[Field]
 *     public Status $status; // → type='enum', settings={enum_class: Status::class}
 *
 * The attribute is data-only — all parameters are exposed as public readonly
 * properties; logic lives in `FieldTypeInferrer` and `EntityMetadataReader`.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class Field
{
    /**
     * @param array<string, mixed> $settings Arbitrary settings; merged with type-inferred settings (e.g. enum_class).
     */
    public function __construct(
        public ?string $type = null,
        public ?bool $required = null,
        public mixed $default = null,
        public string $label = '',
        public string $description = '',
        public array $settings = [],
        public bool $readOnly = false,
        public bool $translatable = false,
        public bool $revisionable = false,
        public FieldStorage $stored = FieldStorage::Column,
        public ?FieldReadLevel $read = null,
    ) {}
}
