<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/**
 * Opaque V2 boundary for the first-party revision and langcode backend.
 *
 * @api
 */
final readonly class RevisionableStorageDriverV2 implements RevisionableStorageDriverV2Interface
{
    public function __construct(
        private RevisionableStorageDriver $backend,
        private StorageRowFactory $rowFactory,
        private StorageSnapshotReader $snapshotReader,
    ) {}

    public function writeRevision(
        string $entityId,
        StorageSnapshot $snapshot,
        ?string $log,
        ?string $langcode = null,
        ?int $author = null,
    ): int {
        return $this->backend->writeRevision(
            $entityId,
            $this->snapshotReader->read($snapshot),
            $log,
            $langcode,
            $author,
        );
    }

    public function updateRevision(string $entityId, int $revisionId, StorageSnapshot $snapshot): void
    {
        $this->backend->updateRevision($entityId, $revisionId, $this->snapshotReader->read($snapshot));
    }

    public function readRevision(string $entityId, int $revisionId): ?StorageRow
    {
        return $this->row($this->backend->readRevision($entityId, $revisionId));
    }

    public function readMultipleRevisions(string $entityId, array $revisionIds): StorageRowSet
    {
        $rows = [];
        foreach ($this->backend->readMultipleRevisions($entityId, $revisionIds) as $key => $row) {
            $rows[$key] = $this->rowFactory->create($row);
        }

        return new StorageRowSet($rows);
    }

    public function getLatestRevisionId(string $entityId): ?int
    {
        return $this->backend->getLatestRevisionId($entityId);
    }

    public function getRevisionIds(string $entityId): array
    {
        return $this->backend->getRevisionIds($entityId);
    }

    public function deleteRevision(string $entityId, int $revisionId): void
    {
        $this->backend->deleteRevision($entityId, $revisionId);
    }

    public function deleteAllRevisions(string $entityId): void
    {
        $this->backend->deleteAllRevisions($entityId);
    }

    public function readLangcodeRevision(string $entityId, string $langcode, int $revisionId): ?StorageRow
    {
        return $this->row($this->backend->readLangcodeRevision($entityId, $langcode, $revisionId));
    }

    public function getLatestLangcodeRevisionId(string $entityId, string $langcode): ?int
    {
        return $this->backend->getLatestLangcodeRevisionId($entityId, $langcode);
    }

    public function getLangcodeRevisionIds(string $entityId, string $langcode): array
    {
        return $this->backend->getLangcodeRevisionIds($entityId, $langcode);
    }

    public function getLangcodesWithRevisions(string $entityId): array
    {
        return $this->backend->getLangcodesWithRevisions($entityId);
    }

    public function currentLangcodeRevision(string $entityId, string $langcode): ?int
    {
        return $this->backend->currentLangcodeRevision($entityId, $langcode);
    }

    public function setCurrentLangcodeRevision(string $entityId, string $langcode, int $revisionId): void
    {
        $this->backend->setCurrentLangcodeRevision($entityId, $langcode, $revisionId);
    }

    public function hasCurrentLangcodeRevision(string $entityId, string $langcode): bool
    {
        return $this->backend->hasCurrentLangcodeRevision($entityId, $langcode);
    }

    /** @param array<string, mixed>|null $row */
    private function row(?array $row): ?StorageRow
    {
        return $row === null ? null : $this->rowFactory->create($row);
    }
}
