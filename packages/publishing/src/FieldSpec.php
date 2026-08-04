<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

/**
 * Writable-field declaration inside a {@see ContentTypeDescriptor}.
 *
 * @api
 */
final readonly class FieldSpec
{
    /**
     * @param 'string'|'text'|'bool'|'int'|'date'|'reference_list' $type Shape enforced on input.
     * @param bool $required Required for BOTH draft creation and publish.
     * @param bool $html The value is an HTML fragment: sanitized against the
     *                   descriptor's explicit editorial allowlist BEFORE
     *                   persistence (unsanitized markup never enters storage
     *                   through this surface).
     * @param ?int $maxLength Maximum length in characters (post-sanitization).
     * @param bool $nullable Whether an explicit null may clear this field.
     * @param ?int $maxItems Maximum item count for a reference list.
     */
    public function __construct(
        public string $type = 'string',
        public bool $required = false,
        public bool $html = false,
        public ?int $maxLength = null,
        public bool $nullable = false,
        public ?int $maxItems = null,
    ) {
        if (!\in_array($this->type, ['string', 'text', 'bool', 'int', 'date', 'reference_list'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown field spec type "%s".', $this->type));
        }
        if ($this->html && $this->type !== 'text') {
            throw new \InvalidArgumentException('HTML fields must use the "text" type.');
        }
        if ($this->maxItems !== null && ($this->type !== 'reference_list' || $this->maxItems < 1)) {
            throw new \InvalidArgumentException('maxItems is a positive reference_list constraint.');
        }
    }
}
