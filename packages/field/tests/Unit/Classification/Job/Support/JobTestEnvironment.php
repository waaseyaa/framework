<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification\Job\Support;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Field\Entity\RetentionPolicy;

/**
 * Shared in-memory test doubles for the classification retention jobs.
 *
 * The jobs touch only EntityStorageInterface (load/query/save/delete) and
 * EntityTypeManager::{getStorage, getDefinitions}, so these compact fakes are
 * enough to exercise their decision logic without a database. The kernel-boot
 * integration test (ClassificationRetentionIntegrationTest) covers the real
 * storage path end-to-end.
 */
trait JobTestEnvironment
{
    /**
     * Build an EntityTypeManager whose getStorage()/getDefinitions() are
     * backed by the supplied in-memory storages.
     *
     * @param array<string, FakeStorage> $storages keyed by entity-type id
     */
    private function makeEntityTypeManager(array $storages): EntityTypeManager
    {
        return new class (new EventDispatcher(), $storages) extends EntityTypeManager {
            /** @param array<string, FakeStorage> $storages */
            public function __construct(
                EventDispatcher $dispatcher,
                private readonly array $storages,
            ) {
                parent::__construct($dispatcher);
            }

            public function getStorage(string $entityTypeId): EntityStorageInterface
            {
                if (!isset($this->storages[$entityTypeId])) {
                    throw new \RuntimeException("No fake storage for '{$entityTypeId}'.");
                }

                return $this->storages[$entityTypeId];
            }

            /** @return array<string, \Waaseyaa\Entity\EntityTypeInterface> */
            public function getDefinitions(): array
            {
                $out = [];
                foreach (array_keys($this->storages) as $id) {
                    $out[$id] = new EntityType(id: $id, label: $id, class: FakeEntity::class);
                }

                return $out;
            }
        };
    }

    /**
     * @param array<int|string, EntityInterface> $entities keyed by id
     */
    private function makeStorage(string $entityTypeId, array $entities = []): FakeStorage
    {
        return new FakeStorage($entityTypeId, $entities);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function makeEntity(string $entityTypeId, int|string $id, array $values): FakeEntity
    {
        $values['id'] = $id;

        return new FakeEntity($entityTypeId, $values);
    }

    /**
     * Build a real RetentionPolicy instance (the jobs `instanceof`-filter the
     * policy storage results, so policy rows must be genuine RetentionPolicy
     * objects — exactly as production storage hydrates them).
     *
     * @param array<string, mixed> $values
     */
    private function makePolicy(int $id, array $values): RetentionPolicy
    {
        $values['id'] = $id;

        return new RetentionPolicy($values);
    }

    private function recordingAuditWriter(): RecordingAuditWriter
    {
        return new RecordingAuditWriter();
    }

    /**
     * An audit writer that throws on the Nth record() call (1-indexed), to
     * verify best-effort isolation between policy iterations.
     */
    private function throwingAuditWriterOnCall(int $failOnCall): ThrowingAuditWriter
    {
        return new ThrowingAuditWriter($failOnCall);
    }
}

/**
 * Audit writer that captures every recorded descriptor for assertions.
 */
final class RecordingAuditWriter implements AuditWriterInterface
{
    /** @var list<AuditEventDescriptor> */
    public array $recorded = [];

    public function record(AuditEventDescriptor $descriptor): void
    {
        $this->recorded[] = $descriptor;
    }
}

/**
 * Audit writer that throws on the Nth record() call (1-indexed), recording
 * all other calls — used to verify best-effort isolation between iterations.
 */
final class ThrowingAuditWriter implements AuditWriterInterface
{
    public int $calls = 0;

    /** @var list<AuditEventDescriptor> */
    public array $recorded = [];

    public function __construct(private readonly int $failOnCall) {}

    public function record(AuditEventDescriptor $descriptor): void
    {
        ++$this->calls;
        if ($this->calls === $this->failOnCall) {
            throw new \RuntimeException('Simulated audit-writer failure.');
        }
        $this->recorded[] = $descriptor;
    }
}

/**
 * Minimal in-memory entity for job tests. Mirrors the EntityInterface surface
 * the jobs read/write (classification_label, uuid, created_at, arbitrary
 * fields). Not a ContentEntityBase to keep the fake free of storage wiring.
 *
 * Non-final so tests can subclass it to attach #[FieldTemplate] PII markers
 * (see RedactJobTest::RedactablePerson).
 */
class FakeEntity implements EntityInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(
        private readonly string $entityTypeId,
        public array $values,
    ) {}

    public function id(): int|string|null
    {
        return $this->values['id'] ?? null;
    }

    public function uuid(): string
    {
        return (string) ($this->values['uuid'] ?? '');
    }

    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }

    public function bundle(): string
    {
        return $this->entityTypeId;
    }

    public function language(): string
    {
        return 'en';
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, mixed $value): static
    {
        $this->values[$name] = $value;

        return $this;
    }

    public function isNew(): bool
    {
        return false;
    }

    public function label(): string
    {
        return (string) ($this->values['label'] ?? '');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    public function getCastSpecForField(string $field): string|array|null
    {
        return null;
    }

    public function enforceIsNew(): void {}
}

/**
 * In-memory EntityStorageInterface backed by an associative array keyed by id.
 */
final class FakeStorage implements EntityStorageInterface
{
    /**
     * @param array<int|string, EntityInterface> $entities
     */
    public function __construct(
        private readonly string $entityTypeId,
        private array $entities = [],
    ) {}

    public function create(array $values = []): EntityInterface
    {
        return new FakeEntity($this->entityTypeId, $values);
    }

    public function load(int|string $id): ?EntityInterface
    {
        return $this->entities[$id] ?? null;
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        foreach ($this->entities as $entity) {
            if ($entity->get($key) === $value) {
                return $entity;
            }
        }

        return null;
    }

    /** @return array<int|string, EntityInterface> */
    public function loadMultiple(array $ids = []): array
    {
        if ($ids === []) {
            return $this->entities;
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($this->entities[$id])) {
                $out[$id] = $this->entities[$id];
            }
        }

        return $out;
    }

    public function save(EntityInterface $entity): int
    {
        $id = $entity->id();
        $isNew = $id === null || !isset($this->entities[$id]);
        if ($id !== null) {
            $this->entities[$id] = $entity;
        }

        return $isNew ? 1 : 2;
    }

    /** @param EntityInterface[] $entities */
    public function delete(array $entities): void
    {
        foreach ($entities as $entity) {
            $id = $entity->id();
            if ($id !== null) {
                unset($this->entities[$id]);
            }
        }
    }

    public function getQuery(): EntityQueryInterface
    {
        return new FakeQuery(array_values($this->entities));
    }

    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }

    /** @return array<int|string, EntityInterface> */
    public function all(): array
    {
        return $this->entities;
    }
}

/**
 * In-memory EntityQueryInterface that filters a fixed entity list by the
 * conditions / exists clauses accumulated through the fluent API, then applies
 * sort and range so the scanner's keyset-batching logic works correctly.
 */
final class FakeQuery implements EntityQueryInterface
{
    /** @var list<array{field: string, value: mixed, op: string}> */
    private array $conditions = [];

    /** @var list<string> */
    private array $existsFields = [];

    private ?string $sortField = null;
    private string $sortDirection = 'ASC';
    private int $rangeOffset = 0;
    private ?int $rangeLimit = null;

    /**
     * @param list<EntityInterface> $entities
     */
    public function __construct(private readonly array $entities) {}

    public function condition(string $field, mixed $value, string $operator = '='): static
    {
        $this->conditions[] = ['field' => $field, 'value' => $value, 'op' => $operator];

        return $this;
    }

    public function exists(string $field): static
    {
        $this->existsFields[] = $field;

        return $this;
    }

    public function notExists(string $field): static
    {
        return $this;
    }

    public function sort(string $field, string $direction = 'ASC'): static
    {
        $this->sortField = $field;
        $this->sortDirection = strtoupper($direction);

        return $this;
    }

    public function range(int $offset, int $limit): static
    {
        $this->rangeOffset = $offset;
        $this->rangeLimit = $limit;

        return $this;
    }

    public function count(): static
    {
        return $this;
    }

    public function accessCheck(bool $check = true): static
    {
        return $this;
    }

    public function setAccount(?\Waaseyaa\Access\AccountInterface $account): static
    {
        return $this;
    }

    /** @return array<int|string> */
    public function execute(): array
    {
        // 1. Filter.
        $filtered = [];
        foreach ($this->entities as $entity) {
            if (!$this->matches($entity)) {
                continue;
            }
            $filtered[] = $entity;
        }

        // 2. Sort by field ASC/DESC when requested.
        if ($this->sortField !== null) {
            $sortField = $this->sortField;
            $dir = $this->sortDirection;
            usort($filtered, static function (EntityInterface $a, EntityInterface $b) use ($sortField, $dir): int {
                $va = $a->get($sortField);
                $vb = $b->get($sortField);
                $cmp = is_numeric($va) && is_numeric($vb)
                    ? ((float) $va <=> (float) $vb)
                    : ((string) ($va ?? '') <=> (string) ($vb ?? ''));

                return $dir === 'DESC' ? -$cmp : $cmp;
            });
        }

        // 3. Apply range (offset + limit) when requested.
        if ($this->rangeLimit !== null) {
            $filtered = array_slice($filtered, $this->rangeOffset, $this->rangeLimit);
        }

        // 4. Collect ids.
        $ids = [];
        foreach ($filtered as $entity) {
            $id = $entity->id();
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function matches(EntityInterface $entity): bool
    {
        foreach ($this->existsFields as $field) {
            $value = $entity->get($field);
            if ($value === null || $value === '') {
                return false;
            }
        }

        foreach ($this->conditions as $cond) {
            if (!$this->matchesCondition($entity->get($cond['field']), $cond['value'], $cond['op'])) {
                return false;
            }
        }

        return true;
    }

    private function matchesCondition(mixed $actual, mixed $expected, string $op): bool
    {
        return match ($op) {
            '=' => $actual === $expected,
            '!=' => $actual !== $expected,
            'IN' => is_array($expected) && in_array($actual, $expected, strict: true),
            'STARTS_WITH' => $actual !== null && str_starts_with((string) $actual, (string) $expected),
            'IS NOT NULL' => $actual !== null,
            '<', '>', '<=', '>=' => $this->compareOrdered($actual, $expected, $op),
            default => false,
        };
    }

    private function compareOrdered(mixed $actual, mixed $expected, string $op): bool
    {
        if ($actual === null) {
            return false;
        }
        $cmp = is_numeric($actual) && is_numeric($expected)
            ? ((float) $actual <=> (float) $expected)
            : ((string) $actual <=> (string) $expected);

        return match ($op) {
            '<' => $cmp < 0,
            '>' => $cmp > 0,
            '<=' => $cmp <= 0,
            '>=' => $cmp >= 0,
            default => false,
        };
    }
}
