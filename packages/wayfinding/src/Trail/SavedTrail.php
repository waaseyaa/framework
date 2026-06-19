<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Trail;

/**
 * Read model for one language of a saved trail: its live (current) value.
 *
 * Returned by {@see TrailStore} reads. `beacons` is the decoded ordered list of
 * `{anchor_id, content, order}`; `origin` is the live-value provenance latch
 * (`recorded` | `human`) the no-silent-overwrite rule reads (FR-011).
 *
 * @api
 *
 * @phpstan-type BeaconShape array{anchor_id: string, content: string, order: int}
 */
final readonly class SavedTrail
{
    /**
     * @param list<BeaconShape> $beacons
     */
    public function __construct(
        public string $id,
        public string $langcode,
        public string $title,
        public array $beacons,
        public string $origin,
        public int $ownerUid,
    ) {}
}
