<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

use Waaseyaa\EntityStorage\Driver\EntityStorageDriverV2Interface;
use Waaseyaa\EntityStorage\Driver\StorageRow;
use Waaseyaa\EntityStorage\Driver\StorageRowFactory;
use Waaseyaa\EntityStorage\Driver\StorageRowSet;
use Waaseyaa\EntityStorage\Driver\StorageSnapshot;
use Waaseyaa\EntityStorage\Driver\StorageSnapshotReader;

/** Consumer-extension fixture proving the additive V2 SPI is implementable. */
final class ThirdPartyOpaqueStorageDriver implements EntityStorageDriverV2Interface
{
    /** @var list<string> */
    public array $operations = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $rows = [];

    public function __construct(
        private readonly StorageRowFactory $rowFactory,
        private readonly StorageSnapshotReader $snapshotReader,
    ) {}

    public function read(string $entityType, string $id, ?string $langcode = null): ?StorageRow
    {
        $this->operations[] = 'read';
        $row = $this->rows[$entityType][$id] ?? null;

        return $row === null ? null : $this->rowFactory->create($row);
    }

    public function readMultiple(string $entityType, array $ids, ?string $langcode = null): StorageRowSet
    {
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->rows[$entityType][(string) $id] ?? null;
            if ($row !== null) {
                $rows[(string) $id] = $this->rowFactory->create($row);
            }
        }

        return new StorageRowSet($rows);
    }

    public function write(string $entityType, string $id, StorageSnapshot $snapshot): string
    {
        $this->operations[] = 'write';
        $this->rows[$entityType][$id] = $this->snapshotReader->read($snapshot);

        return $id;
    }

    public function remove(string $entityType, string $id): void
    {
        unset($this->rows[$entityType][$id]);
    }

    public function exists(string $entityType, string $id): bool
    {
        return isset($this->rows[$entityType][$id]);
    }

    public function count(string $entityType, array $criteria = []): int
    {
        return count($this->matching($entityType, $criteria));
    }

    public function findBy(
        string $entityType,
        array $criteria = [],
        ?array $orderBy = null,
        ?int $limit = null,
    ): StorageRowSet {
        $rows = array_slice($this->matching($entityType, $criteria), 0, $limit, true);

        return new StorageRowSet(array_map($this->rowFactory->create(...), $rows));
    }

    public function findTranslations(
        string $entityType,
        string $id,
        ?string $defaultLangcode = null,
    ): StorageRowSet {
        return new StorageRowSet([]);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, array<string, mixed>>
     */
    private function matching(string $entityType, array $criteria): array
    {
        return array_filter(
            $this->rows[$entityType] ?? [],
            static fn(array $row): bool => array_all(
                $criteria,
                static fn(mixed $expected, string $field): bool => ($row[$field] ?? null) === $expected,
            ),
        );
    }
}
