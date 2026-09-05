<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Read;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Workflows\Read\ActiveWorkflows;
use Waaseyaa\Workflows\Workflow;

#[CoversClass(ActiveWorkflows::class)]
final class ActiveWorkflowsTest extends TestCase
{
    private function configFactory(array $assignments): ConfigFactoryInterface
    {
        return new class ($assignments) implements ConfigFactoryInterface {
            public function __construct(private readonly array $assignments) {}

            public function get(string $name): ConfigInterface
            {
                $data = $this->assignments;

                return new class ($data) implements ConfigInterface {
                    public function __construct(private readonly array $data) {}

                    public function getName(): string { return 'workflows.assignments'; }
                    public function get(string $key = ''): mixed { return $key === '' ? $this->data : ($this->data[$key] ?? null); }
                    public function set(string $key, mixed $value): static { return $this; }
                    public function clear(string $key): static { return $this; }
                    public function delete(): static { return $this; }
                    public function save(): static { return $this; }
                    public function isNew(): bool { return $this->data === []; }
                    public function getRawData(): array { return $this->data; }
                };
            }

            public function getEditable(string $name): ConfigInterface { return $this->get($name); }
            public function loadMultiple(array $names): array { return []; }
            public function rename(string $oldName, string $newName): static { return $this; }
            public function listAll(string $prefix = ''): array { return []; }
        };
    }

    /** @param array<string, Workflow> $workflows */
    private function entityTypeManager(array $workflows): EntityTypeManagerInterface
    {
        return new class ($workflows) implements EntityTypeManagerInterface {
            public function __construct(private readonly array $workflows) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface { throw new \LogicException('not needed'); }
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return false; }
            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed'); }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                $workflows = $this->workflows;

                return new class ($workflows) implements EntityRepositoryInterface {
                    public function __construct(private readonly array $workflows) {}

                    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
                    public function find(int|string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->workflows[$id] ?? null; }
                    public function loadWorkingCopy(int|string $id): ?EntityInterface { return $this->find($id); }

                    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
                    {
                        $found = [];
                        foreach ($ids as $id) {
                            if (isset($this->workflows[$id])) {
                                $found[] = $this->workflows[$id];
                            }
                        }

                        return $found;
                    }

                    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
                    public function getQuery(): EntityQueryInterface { throw new \LogicException('getQuery() must not be used — see ActiveWorkflows docblock'); }
                    public function save(EntityInterface $entity, bool $validate = true): int { throw new \LogicException('not needed'); }
                    public function delete(EntityInterface $entity): void {}
                    public function exists(int|string $id): bool { return isset($this->workflows[$id]); }
                    public function count(array $criteria = []): int { return \count($this->workflows); }
                    public function loadRevision(int|string $entityId, int $revisionId): ?EntityInterface { return null; }
                    public function rollback(int|string $entityId, int $targetRevisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface { throw new \LogicException('not needed'); }
                    public function listRevisions(int|string $entityId): array { return []; }
                    public function setCurrentRevision(int|string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface { throw new \LogicException('not needed'); }
                    public function loadPublishedRevision(int|string $entityId): ?EntityInterface { return null; }
                    public function setPublishedRevision(int|string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface { throw new \LogicException('not needed'); }
                    public function saveMany(array $entities, bool $validate = true): array { return []; }
                    public function deleteMany(array $entities): int { return 0; }
                    public function findTranslations(EntityInterface $entity): array { return []; }
                    public function saveTranslation(int|string $entityId, string $langcode, array $values, ?string $log = null, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): int { return 0; }
                    public function loadTranslation(int|string $entityId, string $langcode): ?EntityInterface { return null; }
                    public function listTranslationRevisions(int|string $entityId, string $langcode): array { return []; }
                };
            }
        };
    }

    #[Test]
    public function returns_empty_when_nothing_is_assigned(): void
    {
        $reader = new ActiveWorkflows($this->configFactory([]), $this->entityTypeManager([]));

        self::assertSame([], $reader->all());
    }

    #[Test]
    public function returns_the_bound_workflow(): void
    {
        $editorial = new Workflow(['id' => 'editorial', 'label' => 'Editorial']);
        $reader = new ActiveWorkflows(
            $this->configFactory(['node.article' => 'editorial']),
            $this->entityTypeManager(['editorial' => $editorial]),
        );

        self::assertSame([$editorial], $reader->all());
    }

    #[Test]
    public function deduplicates_a_workflow_bound_to_multiple_bundles(): void
    {
        $editorial = new Workflow(['id' => 'editorial', 'label' => 'Editorial']);
        $reader = new ActiveWorkflows(
            $this->configFactory(['node.article' => 'editorial', 'node.page' => 'editorial', 'node.*' => 'editorial']),
            $this->entityTypeManager(['editorial' => $editorial]),
        );

        self::assertSame([$editorial], $reader->all());
    }

    #[Test]
    public function returns_multiple_distinct_bound_workflows(): void
    {
        $editorial = new Workflow(['id' => 'editorial', 'label' => 'Editorial']);
        $simple = new Workflow(['id' => 'simple', 'label' => 'Simple']);
        $reader = new ActiveWorkflows(
            $this->configFactory(['node.article' => 'editorial', 'node.page' => 'simple']),
            $this->entityTypeManager(['editorial' => $editorial, 'simple' => $simple]),
        );

        self::assertSame([$editorial, $simple], $reader->all());
    }
}
