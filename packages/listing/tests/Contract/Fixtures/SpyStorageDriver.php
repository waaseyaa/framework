<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Contract\Fixtures;

use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;

/**
 * Records `count()` + `findBy()` calls (and the `$limit` each findBy received)
 * while delegating everything to an inner driver.
 *
 * Used to assert the listing access fast-path actually pushes pagination down to
 * the driver — a SQL `count()` plus a bounded `findBy()` — instead of hydrating
 * the whole result set and counting/slicing in PHP (audit C-28). A regression
 * that silently fell back to full hydration would otherwise pass unnoticed: the
 * returned rows/total are identical either way; only the call pattern differs.
 */
final class SpyStorageDriver implements EntityStorageDriverInterface
{
    public int $countCalls = 0;

    /** @var list<int|null> the `$limit` argument of each findBy() call, in order */
    public array $findByLimits = [];

    public function __construct(private readonly EntityStorageDriverInterface $inner) {}

    public function count(string $entityType, array $criteria = []): int
    {
        $this->countCalls++;

        return $this->inner->count($entityType, $criteria);
    }

    public function findBy(
        string $entityType,
        array $criteria = [],
        ?array $orderBy = null,
        ?int $limit = null,
    ): array {
        $this->findByLimits[] = $limit;

        return $this->inner->findBy($entityType, $criteria, $orderBy, $limit);
    }

    public function read(string $entityType, string $id, ?string $langcode = null): ?array
    {
        return $this->inner->read($entityType, $id, $langcode);
    }

    public function readMultiple(string $entityType, array $ids, ?string $langcode = null): array
    {
        return $this->inner->readMultiple($entityType, $ids, $langcode);
    }

    public function write(string $entityType, string $id, array $values): string
    {
        return $this->inner->write($entityType, $id, $values);
    }

    public function remove(string $entityType, string $id): void
    {
        $this->inner->remove($entityType, $id);
    }

    public function exists(string $entityType, string $id): bool
    {
        return $this->inner->exists($entityType, $id);
    }

    public function findTranslations(
        string $entityType,
        string $id,
        ?string $defaultLangcode = null,
    ): array {
        return $this->inner->findTranslations($entityType, $id, $defaultLangcode);
    }
}
