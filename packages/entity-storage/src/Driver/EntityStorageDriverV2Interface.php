<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/**
 * Opaque-row replacement for the stable raw-array V1 storage SPI.
 *
 * @api
 */
interface EntityStorageDriverV2Interface
{
    public function read(string $entityType, string $id, ?string $langcode = null): ?StorageRow;

    /** @param list<int|string> $ids */
    public function readMultiple(string $entityType, array $ids, ?string $langcode = null): StorageRowSet;

    public function write(string $entityType, string $id, StorageSnapshot $snapshot): string;

    public function remove(string $entityType, string $id): void;

    public function exists(string $entityType, string $id): bool;

    /** @param array<string, mixed> $criteria */
    public function count(string $entityType, array $criteria = []): int;

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     */
    public function findBy(
        string $entityType,
        array $criteria = [],
        ?array $orderBy = null,
        ?int $limit = null,
    ): StorageRowSet;

    public function findTranslations(
        string $entityType,
        string $id,
        ?string $defaultLangcode = null,
    ): StorageRowSet;
}
