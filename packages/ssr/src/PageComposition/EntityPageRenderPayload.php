<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\PageComposition;

/**
 * Immutable, presentation-only payload for application entity-page shells.
 *
 * Contains no entity, account, repository, arbitrary raw field bag, or
 * template name. bodyCompositionHtml is the one deliberate structure-
 * preserving channel: framework-sanitized HTML from an access-authorized body
 * field for a code-owned application normalizer.
 *
 * @api
 */
final readonly class EntityPageRenderPayload
{
    /**
     * @param array<string, EntityPageField> $fields
     */
    public function __construct(
        public string $title,
        public string $requestPath,
        public string $entityType,
        public string $bundle,
        public string $viewMode,
        public string $langcode,
        public array $fields,
        public string $schemaOrgJsonLd,
        public string $bodyCompositionHtml,
    ) {}

    public function field(string $name): ?EntityPageField
    {
        return $this->fields[$name] ?? null;
    }

    public function bodyHtml(): string
    {
        $body = $this->field('body');

        return $body === null ? '' : $body->formatted;
    }
}
