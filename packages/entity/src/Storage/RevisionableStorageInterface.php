<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Storage;

use Waaseyaa\Entity\EntityInterface;

interface RevisionableStorageInterface extends EntityStorageInterface
{
    public function loadRevision(int|string $entityId, int $revisionId): ?EntityInterface;

    /** @return array<int, EntityInterface> */
    public function loadMultipleRevisions(int|string $entityId, array $revisionIds): array;

    public function deleteRevision(int|string $entityId, int $revisionId): void;

    public function getLatestRevisionId(int|string $entityId): ?int;

    /** @return int[] Revision IDs in ascending order. */
    public function getRevisionIds(int|string $entityId): array;
}
