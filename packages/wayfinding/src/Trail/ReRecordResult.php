<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Trail;

/**
 * Outcome of re-recording a live trail onto an existing saved trail (FR-011).
 *
 * `promoted` is true when the re-recording advanced the live trail (the target
 * was not human-owned, so there was nothing to overwrite); false when it landed
 * as a **draft revision** behind a human-owned live value, which is left
 * untouched. Either way a new revision was created — `revisionId` is its
 * per-language revision id.
 *
 * @api
 */
final readonly class ReRecordResult
{
    public function __construct(
        public bool $promoted,
        public int $revisionId,
    ) {}
}
