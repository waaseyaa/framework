<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/**
 * Opaque V2 boundary for the first-party in-memory backend.
 *
 * @api
 */
final readonly class InMemoryStorageDriverV2 implements EntityStorageDriverV2Interface
{
    public function __construct(
        private InMemoryStorageDriver $backend,
        private StorageRowFactory $rowFactory,
        private StorageSnapshotReader $snapshotReader,
    ) {}

    public function read(string $entityType, string $id, ?string $langcode = null): ?StorageRow
    {
        $row = $this->backend->read($entityType, $id, $langcode);

        return $row === null ? null : $this->rowFactory->create($row);
    }

    public function readMultiple(string $entityType, array $ids, ?string $langcode = null): StorageRowSet
    {
        return $this->rows($this->backend->readMultiple($entityType, $ids, $langcode));
    }

    public function write(string $entityType, string $id, StorageSnapshot $snapshot): string
    {
        return $this->backend->write($entityType, $id, $this->snapshotReader->read($snapshot));
    }

    public function remove(string $entityType, string $id): void
    {
        $this->backend->remove($entityType, $id);
    }

    public function exists(string $entityType, string $id): bool
    {
        return $this->backend->exists($entityType, $id);
    }

    public function count(string $entityType, array $criteria = []): int
    {
        return $this->backend->count($entityType, $criteria);
    }

    public function findBy(
        string $entityType,
        array $criteria = [],
        ?array $orderBy = null,
        ?int $limit = null,
    ): StorageRowSet {
        return $this->rows($this->backend->findBy($entityType, $criteria, $orderBy, $limit));
    }

    public function findTranslations(
        string $entityType,
        string $id,
        ?string $defaultLangcode = null,
    ): StorageRowSet {
        return $this->rows($this->backend->findTranslations($entityType, $id, $defaultLangcode));
    }

    /**
     * @param array<int|string, array<string, mixed>> $rows
     */
    private function rows(array $rows): StorageRowSet
    {
        $opaque = [];
        foreach ($rows as $key => $row) {
            $opaque[$key] = $this->rowFactory->create($row);
        }

        return new StorageRowSet($opaque);
    }
}
