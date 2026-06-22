<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Document;

use Waaseyaa\Search\SearchIndexableInterface;

/**
 * A plain, entity-free indexable document. The search engine is otherwise
 * entity-driven (entities implement SearchIndexableInterface and are indexed
 * on save/delete events); this value object lets a consumer index a file or
 * any other non-entity document — a spec page, a markdown corpus, a synced
 * doc set — through the exact same indexer contract.
 *
 * The document_id is opaque (e.g. "spec:entity-system"); entity_type defaults
 * to "document" so the NOT NULL metadata column is always satisfied, and any
 * metadata key the caller supplies overrides the default.
 *
 * @api Public indexable for consuming apps that index files, not entities.
 */
final class SearchDocument implements SearchIndexableInterface
{
    /**
     * @param array<string, mixed> $metadata extra search_metadata columns
     *        (entity_type, content_type, source_name, quality_score, topics,
     *        url, og_image, created_at); entity_type defaults to "document".
     */
    public function __construct(
        private readonly string $id,
        private readonly string $title,
        private readonly string $body,
        private readonly array $metadata = [],
    ) {}

    public function getSearchDocumentId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, string>
     */
    public function toSearchDocument(): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchMetadata(): array
    {
        // Caller-supplied keys win; the default only fills entity_type when the
        // caller omits it (the metadata column is NOT NULL).
        return $this->metadata + ['entity_type' => 'document'];
    }
}
