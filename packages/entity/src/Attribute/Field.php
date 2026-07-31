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
 * `indexed:` requests a physical B-tree index (#2157). Two rules govern it:
 *
 *  - **Contract:** `indexed: true` is permitted **only** with
 *    `stored: FieldStorage::Column`. Pairing it with `FieldStorage::Data` is
 *    rejected at construction. This is an API rule that keeps a request for an
 *    index an explicit declaration of indexable intent — it is *not* a claim
 *    that no such column could physically exist. (The current sql-column
 *    backend materialises every declared field, including Data-stored ones;
 *    that behaviour is pre-existing and may change, and the attribute API does
 *    not promise it either way.)
 *  - **Materialisation:** whether the index is actually created depends on the
 *    *entity type's* primary storage backend, not the field. Only `sql-column`
 *    creates one. Declaring `indexed: true` on a type that resolves to
 *    `sql-blob` raises `UnmaterializableIndexException` at schema-sync time
 *    rather than failing silently.
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
        public bool $indexed = false,
    ) {
        // API contract, not a physical claim: `indexed: true` is permitted only
        // alongside `FieldStorage::Column`, so that requesting an index is
        // always an explicit declaration of indexable intent on a field the
        // developer has also declared column-shaped.
        //
        // Deliberately NOT justified as "impossible": the current sql-column
        // backend materialises *every* declared field, including Data-stored
        // ones, so such a column can exist today. That is pre-existing backend
        // behaviour which may change, and it is not something the attribute API
        // promises. Rejecting the combination here keeps the declaration honest
        // regardless of which way the backend goes.
        //
        // Caught at construction rather than schema time so the developer sees
        // it the moment the class is read.
        if ($indexed && $stored === FieldStorage::Data) {
            throw new \InvalidArgumentException(
                'A field cannot declare both indexed: true and stored: FieldStorage::Data. '
                . 'indexed: true is permitted only with stored: FieldStorage::Column, which is how a '
                . 'field declares that it is column-shaped and therefore indexable. Either move the '
                . 'field to FieldStorage::Column, or drop indexed: true.',
            );
        }
    }
}
