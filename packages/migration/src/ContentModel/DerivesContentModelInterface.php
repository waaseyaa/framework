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
 * Blessed and wired (G-026, #1940): implement this on a `ServiceProvider`
 * (same pattern as {@see \Waaseyaa\Migration\Discovery\HasMigrationsInterface})
 * and `AbstractKernel::injectContentModelProviders()` collects it at boot,
 * exactly like `injectMigrationProviders()` does for `HasMigrationsInterface`.
 * `Waaseyaa\Migration\ServiceProvider` accepts the collected providers via
 * {@see \Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsContentModelProvidersInterface}
 * and threads them into {@see \Waaseyaa\Migration\Runner\MigrationRunner},
 * where full registration runs once before the first migration of the CLI
 * invocation. The migration provider also calls this method during later
 * boots to restore process-local definitions for bundle configs already
 * persisted by an import (#1982). See docs/specs/migration-platform.md
 * "Content model registration".
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
