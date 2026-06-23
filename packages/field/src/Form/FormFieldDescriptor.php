<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Form;

/**
 * Immutable value object describing a single form field.
 *
 * Produced by FormDescriptorBuilder::build() for each field in a bundle.
 * The `value` property holds whatever EntityInterface::get() returned for
 * the field — the raw stored value (storage-canonical form) or null for unset fields.
 * Consumers (template layer, Vue components) are responsible for extracting
 * scalar values from the field item list; this object is markup-free.
 *
 * @phpstan-type FieldErrors list<string>
 * @api
 */
final readonly class FormFieldDescriptor
{
    /**
     * @param list<string> $errors Validation error messages for this field.
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public string $group,
        public mixed $value,
        public bool $readOnly,
        public bool $required,
        public array $errors = [],
    ) {}
}
