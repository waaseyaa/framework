<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Access;

/**
 * Decides whether the current acting account may see an indexed document in
 * search results.
 *
 * The FTS5 index is populated automatically on entity save, so it can hold
 * documents the requesting account is not allowed to view (e.g. unpublished
 * nodes). The search provider consults a checker for every candidate hit and
 * drops the ones this returns `false` for, so the read path enforces the same
 * access policy as the rest of the framework instead of failing open.
 *
 * @internal Parked with the first-party search read surface.
 */
interface SearchAccessChecker
{
    /**
     * @param string $documentId Opaque index id, e.g. `"node:42"` or `"spec:entity-system"`.
     * @param string $entityType The indexed `entity_type` metadata, e.g. `"node"` or `"document"`.
     *
     * @return bool True if the current acting account may see this document.
     */
    public function canView(string $documentId, string $entityType): bool;
}
