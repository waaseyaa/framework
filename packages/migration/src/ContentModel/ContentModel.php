<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\ContentModel;

/**
 * A content model inferred from a source by a {@see DerivesContentModelInterface}.
 *
 * This is the generic, source-agnostic description of "what content types and
 * fields a source contains". The framework {@see ContentModelRegistrar} turns it
 * into registered content types (bundle config entities) and declared, typed
 * field definitions, so that downstream content imports write into declared
 * fields rather than an opaque blob.
 *
 * Blessed as the supported import-time content-model surface (G-026, #1940):
 * a {@see DerivesContentModelInterface} provider returns one of these, and
 * {@see \Waaseyaa\Migration\Runner\MigrationRunner} feeds it through
 * {@see ContentModelRegistrar} before the first migration runs. See
 * docs/specs/migration-platform.md "Content model registration" for the full
 * contract, including the boundary against the static
 * `#[BundleTemplate]`/`#[FieldTemplate]` app-model path.
 *
 * @api
 */
final readonly class ContentModel
{
    /**
     * @param list<ContentTypeModel> $types Content types in the model.
     * @param list<string> $vocabularies Taxonomy vocabulary ids the source uses.
     * @param list<string> $notes Model-level normalization notes (generic, logged).
     */
    public function __construct(
        public array $types = [],
        public array $vocabularies = [],
        public array $notes = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->types === [];
    }
}
