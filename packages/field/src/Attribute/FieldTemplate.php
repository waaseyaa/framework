<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Attribute;

/**
 * @api Forthcoming extension point: WP03 (`T-O`) of
 *      `classification-retention-engine-01KSEFTH` reads `$pii` via reflection
 *      to drive the redact job. Declared here so authors can mark fields ahead
 *      of the consumer landing.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class FieldTemplate
{
    /**
     * @param list<string> $promptAliases
     * @param bool $pii When true, signals that this field may contain personally
     *                  identifiable information. Used by the classification engine
     *                  (WP01) and downstream access-policy evaluation. Default false.
     *                  Additive — existing callers that omit this parameter are unaffected.
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
    ) {}
}
