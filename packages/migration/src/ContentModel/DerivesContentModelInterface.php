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
 * UNWIRED / EXPERIMENTAL (audit C-5): no kernel discovery dispatches this
 * interface yet (the capability bus only wires {@see HasMigrationsInterface}),
 * and {@see ContentModelRegistrar} is bound by no service provider. This is a
 * forthcoming-feature stub, not part of the supported public surface. @api is
 * retained only so the dead-code gate does not flag the planned extension point.
 *
 * @internal Unsupported scaffolding pending wiring; shape may change without a
 *           deprecation cycle. See docs/specs/migration-platform.md.
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
