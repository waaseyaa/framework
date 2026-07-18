<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Attribute;

use Waaseyaa\Entity\FieldReadLevel;

/**
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class FieldTemplate
{
    /**
     * @param list<string> $promptAliases
     * @param bool $pii When true, signals that this field may contain personally
     *                  identifiable information. Consumed by
     *                  {@see \Waaseyaa\Field\Classification\Job\RedactJob::discoverPiiFields()}
     *                  to drive PII redaction sweeps, and by downstream
     *                  access-policy evaluation. Default false. Additive —
     *                  existing callers that omit this parameter are unaffected.
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $label = '',
        public string $group = '',
        public array $promptAliases = [],
        public bool $required = false,
        public bool $readOnly = false,
        public bool $pii = false,
        public ?FieldReadLevel $read = null,
    ) {}
}
