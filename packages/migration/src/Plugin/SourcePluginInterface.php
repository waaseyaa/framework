<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Plugin;

use Waaseyaa\Migration\SourceId;

/**
 * Contract every source plugin implements.
 *
 * Source plugins are the producer end of the migration pipeline. They yield
 * {@see SourceRecord} instances from external systems (databases, CSV files,
 * HTTP APIs, etc.) and produce a deterministic {@see SourceId} for each row so
 * the id-map can record the source -> destination mapping.
 *
 * Implementations MUST be deterministic across runs: the same input data must
 * yield the same `SourceId` so re-imports are idempotent.
 *
 * @api
 */
interface SourcePluginInterface
{
    /**
     * Globally unique plugin identifier (snake_case, e.g. `wordpress_post`).
     *
     * Used by migration definitions and operator diagnostics.
     */
    public function id(): string;

    /**
     * Plugin stability marker — either `'stable'` or `'experimental'`.
     *
     * Experimental plugins emit a deprecation notice on first use per process,
     * via the {@see \Waaseyaa\Migration\Log\Channels::MIGRATION_DEPRECATION}
     * channel.
     */
    public function stability(): string;

    /**
     * Yield source records lazily.
     *
     * A source plugin that yields zero records is valid (e.g. when the source
     * is empty or filtered to nothing) — see {@see count()}.
     *
     * @return iterable<SourceRecord>
     */
    public function records(): iterable;

    /**
     * Compute the canonical SourceId for a given record.
     *
     * Implementations MUST be pure: identical records always yield identical
     * `SourceId` values.
     */
    public function sourceIdFor(SourceRecord $record): SourceId;

    /**
     * Total number of records the source will yield, or null if unknown.
     *
     * Used by progress reporters and chunking; may return 0 for empty sources
     * or null when counting up-front is impractical (e.g. streaming APIs).
     */
    public function count(): ?int;
}
