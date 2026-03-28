<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * Configurable entity storage stub.
 *
 * @internal Test double for Relationship package tests.
 */
class StubEntityStorage implements EntityStorageInterface
{
    private readonly \Closure $loadHandler;

    /**
     * @param ?\Closure(int|string): ?EntityInterface $loadHandler Controls load() behavior.
     *        Default: returns a minimal stub entity for any ID.
     * @param ?EntityQueryInterface $query Returned by getQuery(). Default: NullEntityQuery.
     * @param string $entityTypeId Returned by getEntityTypeId().
     */
    public function __construct(
        ?\Closure $loadHandler = null,
        private readonly ?EntityQueryInterface $query = null,
        private readonly string $entityTypeId = 'node',
    ) {
        $this->loadHandler = $loadHandler ?? static function (int|string $id): EntityInterface {
            return new class ($id) implements EntityInterface {
                public function __construct(private readonly int|string $id) {}
                public function id(): int|string|null { return $this->id; }
                public function uuid(): string { return ''; }
                public function label(): string { return 'test'; }
                public function getEntityTypeId(): string { return 'node'; }
                public function bundle(): string { return 'default'; }
                public function isNew(): bool { return false; }
                public function get(string $name): mixed { return null; }
                public function set(string $name, mixed $value): static { return $this; }
                public function toArray(): array { return []; }
                public function language(): string { return 'en'; }
            };
        };
    }

    public function load(int|string $id): ?EntityInterface
    {
        return ($this->loadHandler)($id);
    }

    public function getQuery(): EntityQueryInterface
    {
        return $this->query ?? new NullEntityQuery();
    }

    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }

    public function create(array $values = []): EntityInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function loadMultiple(array $ids = []): array
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function save(EntityInterface $entity): int
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function delete(array $entities): void
    {
        throw new \BadMethodCallException('Not implemented.');
    }
}
