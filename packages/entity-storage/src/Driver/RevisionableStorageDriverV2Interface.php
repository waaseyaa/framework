<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/** Additive opaque revision-driver SPI matching the current revision surface. @api */
interface RevisionableStorageDriverV2Interface
{
    public function writeRevision(
        string $entityId,
        StorageSnapshot $snapshot,
        ?string $log,
        ?string $langcode = null,
        ?int $author = null,
    ): int;

    public function updateRevision(string $entityId, int $revisionId, StorageSnapshot $snapshot): void;

    public function readRevision(string $entityId, int $revisionId): ?StorageRow;

    /** @param list<int> $revisionIds */
    public function readMultipleRevisions(string $entityId, array $revisionIds): StorageRowSet;

    public function getLatestRevisionId(string $entityId): ?int;

    /** @return list<int> */
    public function getRevisionIds(string $entityId): array;

    public function deleteRevision(string $entityId, int $revisionId): void;

    public function deleteAllRevisions(string $entityId): void;

    public function readLangcodeRevision(string $entityId, string $langcode, int $revisionId): ?StorageRow;

    public function getLatestLangcodeRevisionId(string $entityId, string $langcode): ?int;

    /** @return list<int> */
    public function getLangcodeRevisionIds(string $entityId, string $langcode): array;

    /** @return list<string> */
    public function getLangcodesWithRevisions(string $entityId): array;

    public function currentLangcodeRevision(string $entityId, string $langcode): ?int;

    public function setCurrentLangcodeRevision(string $entityId, string $langcode, int $revisionId): void;

    public function hasCurrentLangcodeRevision(string $entityId, string $langcode): bool;
}
