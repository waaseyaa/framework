<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

/**
 * App-owned declaration of ONE publishable content shape.
 *
 * The framework owns HOW content mutates safely ({@see ContentPublisher});
 * the app owns WHAT the content is: entity type + bundle, the writable field
 * schema, the explicit editorial HTML allowlist, the editorial validators,
 * and the capability string that gates every mutation. A descriptor is
 * deliberately bundle-scoped — this surface never exposes arbitrary entity
 * writes.
 *
 * @api
 */
final readonly class ContentTypeDescriptor
{
    /**
     * @param string $entityTypeId Target entity type (e.g. 'node').
     * @param ?string $bundle Target bundle (e.g. 'article'); null for bundle-less types.
     * @param string $slugField Unique-per-bundle human key field (writable, required).
     * @param string $statusField Publish flag field. NOT writable through payloads —
     *                            only publish()/unpublish() may move it.
     * @param array<string, FieldSpec> $writableFields Complete writable surface;
     *                            any payload key outside this map (or equal to the
     *                            status field) is rejected field-specifically.
     * @param ?ContentHtmlSanitizerInterface $htmlSanitizer Explicit allowlist-based sanitizer
     *                            applied to every {@see FieldSpec::$html} field before persistence.
     *                            REQUIRED when any writable field declares html.
     * @param list<ContentValidatorInterface> $validators App editorial rules.
     * @param string $publishCapability Permission string required for every mutation
     *                            (e.g. 'publish rht articles').
     */
    public function __construct(
        public string $entityTypeId,
        public ?string $bundle,
        public string $slugField,
        public string $statusField,
        public array $writableFields,
        public ?ContentHtmlSanitizerInterface $htmlSanitizer,
        public array $validators,
        public string $publishCapability,
    ) {
        if ($this->entityTypeId === '' || $this->slugField === '' || $this->statusField === '' || $this->publishCapability === '') {
            throw new \InvalidArgumentException('Descriptor requires entityTypeId, slugField, statusField, and publishCapability.');
        }
        foreach ($this->writableFields as $fieldSpec) {
            if ($fieldSpec->html && $this->htmlSanitizer === null) {
                throw new \InvalidArgumentException('A ContentHtmlSanitizerInterface is required when HTML fields are declared.');
            }
        }
        if (!isset($this->writableFields[$this->slugField])) {
            throw new \InvalidArgumentException(sprintf('Slug field "%s" must be declared writable.', $this->slugField));
        }
        if (isset($this->writableFields[$this->statusField])) {
            throw new \InvalidArgumentException(sprintf(
                'Status field "%s" must not be writable through payloads; only publish()/unpublish() move it.',
                $this->statusField,
            ));
        }
    }
}
