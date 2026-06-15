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
 * UNWIRED / EXPERIMENTAL (audit C-5): part of the unwired ContentModel
 * scaffolding; no production path constructs or consumes it. @api retained only
 * for the dead-code gate.
 *
 * @internal Unsupported scaffolding pending wiring. See docs/specs/migration-platform.md.
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
