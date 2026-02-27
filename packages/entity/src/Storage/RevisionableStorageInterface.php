<?php

declare(strict_types=1);

namespace Aurora\Entity\Storage;

use Aurora\Entity\EntityInterface;

interface RevisionableStorageInterface extends EntityStorageInterface
{
    public function loadRevision(int|string $revisionId): ?EntityInterface;

    /** @return array<int|string, EntityInterface> */
    public function loadMultipleRevisions(array $ids): array;

    public function deleteRevision(int|string $revisionId): void;

    public function getLatestRevisionId(int|string $entityId): int|string|null;
}
