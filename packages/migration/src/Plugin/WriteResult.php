<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Plugin;

use Waaseyaa\Migration\Advisory\SaveAdvisoryEvidence;

/**
 * Outcome of a successful destination write.
 *
 * Persisted in the id-map so subsequent runs can resolve source -> destination
 * references via {@see DestinationPluginInterface::lookup()} and unwind a
 * migration with {@see DestinationPluginInterface::rollback()}.
 *
 * @api
 */
final readonly class WriteResult
{
    /** @var list<SaveAdvisoryEvidence> */
    public array $acknowledgedSaveAdvisories;

    /**
     * @param string $destinationEntityType Destination entity type id (e.g. `node`, `taxonomy_term`). Non-empty.
     * @param string $destinationUuid UUIDv7 of the persisted destination entity. Non-empty.
     * @param string $sourceRecordHash Canonical hash of the {@see DestinationRecord::$values} written. WP04 will populate this with a deterministic sha256; WP01 accepts any non-empty string so callers can wire the shape.
     * @param string $runId UUIDv7 of the producing migration run. Non-empty.
     * @param string $writtenAt ISO 8601 UTC timestamp of the write (e.g. `2026-05-12T22:56:07Z`). Non-empty.
     * @param array<int, mixed> $acknowledgedSaveAdvisories Transient evidence from this write; never persisted into the id-map.
     *
     * @throws \InvalidArgumentException If any field is empty.
     */
    public function __construct(
        public string $destinationEntityType,
        public string $destinationUuid,
        public string $sourceRecordHash,
        public string $runId,
        public string $writtenAt,
        array $acknowledgedSaveAdvisories = [],
    ) {
        if ($destinationEntityType === '') {
            throw new \InvalidArgumentException('WriteResult::$destinationEntityType must be a non-empty string.');
        }
        if ($destinationUuid === '') {
            throw new \InvalidArgumentException('WriteResult::$destinationUuid must be a non-empty string.');
        }
        if ($sourceRecordHash === '') {
            throw new \InvalidArgumentException('WriteResult::$sourceRecordHash must be a non-empty string.');
        }
        if ($runId === '') {
            throw new \InvalidArgumentException('WriteResult::$runId must be a non-empty string.');
        }
        if ($writtenAt === '') {
            throw new \InvalidArgumentException('WriteResult::$writtenAt must be a non-empty string.');
        }
        if (!array_is_list($acknowledgedSaveAdvisories)) {
            throw new \InvalidArgumentException('WriteResult advisory evidence must be a list.');
        }
        if (count($acknowledgedSaveAdvisories) > 32) {
            throw new \InvalidArgumentException('WriteResult advisory evidence must not exceed 32 entries.');
        }
        foreach ($acknowledgedSaveAdvisories as $evidence) {
            if (!$evidence instanceof SaveAdvisoryEvidence) {
                throw new \InvalidArgumentException('WriteResult advisory evidence has an invalid entry.');
            }
        }
        /** @var list<SaveAdvisoryEvidence> $acknowledgedSaveAdvisories */
        $this->acknowledgedSaveAdvisories = $acknowledgedSaveAdvisories;
    }
}
