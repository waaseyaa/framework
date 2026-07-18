<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/**
 * Dormant repository-only bridge for V1 drivers. Existing V1 callers and
 * repositories are not routed through this adapter during WP1.
 *
 * @internal
 */
final class LegacyStorageDriverAdapter implements EntityStorageDriverV2Interface
{
    /** @var \Closure(string, array<string, mixed>): void */
    private readonly \Closure $deprecationEmitter;

    /** @param callable(string, array<string, mixed>): void $deprecationEmitter */
    public function __construct(
        private readonly EntityStorageDriverInterface $legacy,
        private readonly StorageRowFactory $rowFactory,
        private readonly StorageSnapshotReader $snapshotReader,
        callable $deprecationEmitter,
    ) {
        $this->deprecationEmitter = \Closure::fromCallable($deprecationEmitter);
        ($this->deprecationEmitter)('entity.deprecation', [
            'event' => 'v1_storage_driver_adapter',
            'driver' => $legacy::class,
        ]);
    }

    public function read(string $entityType, string $id, ?string $langcode = null): ?StorageRow
    {
        $row = $this->legacy->read($entityType, $id, $langcode);

        return $row === null ? null : $this->rowFactory->create($row);
    }

    public function readMultiple(string $entityType, array $ids, ?string $langcode = null): StorageRowSet
    {
        return $this->wrapRows($this->legacy->readMultiple($entityType, $ids, $langcode));
    }

    public function write(string $entityType, string $id, StorageSnapshot $snapshot): string
    {
        return $this->legacy->write($entityType, $id, $this->snapshotReader->read($snapshot));
    }

    public function remove(string $entityType, string $id): void
    {
        $this->legacy->remove($entityType, $id);
    }

    public function exists(string $entityType, string $id): bool
    {
        return $this->legacy->exists($entityType, $id);
    }

    public function count(string $entityType, array $criteria = []): int
    {
        return $this->legacy->count($entityType, $criteria);
    }

    public function findBy(string $entityType, array $criteria = [], ?array $orderBy = null, ?int $limit = null): StorageRowSet
    {
        return $this->wrapRows($this->legacy->findBy($entityType, $criteria, $orderBy, $limit));
    }

    public function findTranslations(string $entityType, string $id, ?string $defaultLangcode = null): StorageRowSet
    {
        return $this->wrapRows($this->legacy->findTranslations($entityType, $id, $defaultLangcode));
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    private function wrapRows(array $rows): StorageRowSet
    {
        return new StorageRowSet(array_map(
            fn(array $row): StorageRow => $this->rowFactory->create($row),
            $rows,
        ));
    }
}
