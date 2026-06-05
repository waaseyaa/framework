<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\ContentModel;

/**
 * Implemented by a source-reading provider that can infer a {@see ContentModel}
 * from its source.
 *
 * The framework registers the returned model (content types + typed fields)
 * before content is imported, so the import writes into declared fields. This is
 * the generic seam: any source reader (WordPress, Drupal, CSV, ...) that can
 * inspect its source and describe a content model participates the same way.
 * Mirrors {@see \Waaseyaa\Migration\Discovery\HasMigrationsInterface}.
 *
 * @api
 */
interface DerivesContentModelInterface
{
    /**
     * Inspect the source and return the inferred content model, or null when the
     * source is not available (so registration is a safe no-op, e.g. before the
     * source mirror has been built).
     */
    public function deriveContentModel(): ?ContentModel;
}
