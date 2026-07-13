<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\TranslatableInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Workflow;

/**
 * Minimal two-axis (revisionable + translatable) stub class satisfying
 * {@see EntityType}'s own translatable-registration guard (a translatable
 * entity type's registered class must implement {@see TranslatableInterface}) —
 * exists only so `binding_a_revisionable_and_translatable_type_throws()` can
 * construct a valid two-axis EntityType fixture. None of its methods are
 * exercised: the resolver throws before ever instantiating this class.
 */
final class WorkflowBindingResolverTwoAxisStub implements TranslatableInterface
{
    public function defaultLangcode(): string { return 'en'; }
    public function activeLangcode(): string { return 'en'; }
    public function language(): string { return 'en'; }
    public function hasTranslation(string $langcode): bool { return false; }
    public function getTranslation(string $langcode): static { return $this; }
    public function addTranslation(string $langcode): static { return $this; }
    public function removeTranslation(string $langcode): void {}
    public function translations(): iterable { return []; }
    public function getTranslationLanguages(): array { return []; }
    public function fieldLangcode(string $fieldName): ?string { return null; }
}

/**
 * @covers \Waaseyaa\Workflows\Binding\WorkflowBindingResolver
 */
#[CoversClass(WorkflowBindingResolver::class)]
final class WorkflowBindingResolverTest extends TestCase
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

    private function entityTypeManager(array $definitions, array $workflows): EntityTypeManagerInterface
    {
        return new class ($definitions, $workflows) implements EntityTypeManagerInterface {
            public function __construct(
                private readonly array $definitions,
                private readonly array $workflows,
            ) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                return $this->definitions[$entityTypeId];
            }

            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return $this->definitions; }
            public function hasDefinition(string $entityTypeId): bool { return isset($this->definitions[$entityTypeId]); }

            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed: production getStorage() has no storageFactory (C-22 WP4), so the binding resolver uses getRepository()'); }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                $workflows = $this->workflows;

                return new class ($workflows) implements EntityRepositoryInterface {
                    public function __construct(private readonly array $workflows) {}

                    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }

                    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
                    {
                        return $this->workflows[$id] ?? null;
                    }

                    public function loadWorkingCopy(string $id): ?EntityInterface
                    {
                        return $this->find($id);
                    }

                    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
                    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
                    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }
                    public function save(EntityInterface $entity, bool $validate = true): int { throw new \LogicException('not needed'); }
                    public function delete(EntityInterface $entity): void {}
                    public function exists(string $id): bool { return isset($this->workflows[$id]); }
                    public function count(array $criteria = []): int { return \count($this->workflows); }
                    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface { return null; }
                    public function rollback(string $entityId, int $targetRevisionId): EntityInterface { throw new \LogicException('not needed'); }
                    public function listRevisions(string $entityId): array { return []; }
                    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface { throw new \LogicException('not needed'); }
                    public function loadPublishedRevision(string $entityId): ?EntityInterface { return null; }
                    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface { throw new \LogicException('not needed'); }
                    public function saveMany(array $entities, bool $validate = true): array { return []; }
                    public function deleteMany(array $entities): int { return 0; }
                    public function findTranslations(EntityInterface $entity): array { return []; }
                    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int { return 0; }
                    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface { return null; }
                    public function listTranslationRevisions(string $entityId, string $langcode): array { return []; }
                };
            }
        };
    }

    #[Test]
    public function resolves_an_exact_bundle_binding(): void
    {
        $editorial = new Workflow(['id' => 'editorial', 'label' => 'Editorial']);
        $resolver = new WorkflowBindingResolver(
            $this->configFactory(['node.article' => 'editorial']),
            $this->entityTypeManager(
                ['node' => new EntityType(id: 'node', label: 'Content', class: \stdClass::class, keys: ['id' => 'nid', 'revision' => 'vid'], revisionable: true)],
                ['editorial' => $editorial],
            ),
        );

        $this->assertSame($editorial, $resolver->resolve('node', 'article'));
    }

    #[Test]
    public function resolves_a_wildcard_bundle_binding(): void
    {
        $editorial = new Workflow(['id' => 'editorial', 'label' => 'Editorial']);
        $resolver = new WorkflowBindingResolver(
            $this->configFactory(['node.*' => 'editorial']),
            $this->entityTypeManager(
                ['node' => new EntityType(id: 'node', label: 'Content', class: \stdClass::class, keys: ['id' => 'nid', 'revision' => 'vid'], revisionable: true)],
                ['editorial' => $editorial],
            ),
        );

        $this->assertSame($editorial, $resolver->resolve('node', 'page'));
    }

    #[Test]
    public function exact_bundle_key_wins_over_wildcard(): void
    {
        $editorial = new Workflow(['id' => 'editorial', 'label' => 'Editorial']);
        $simple = new Workflow(['id' => 'simple', 'label' => 'Simple']);
        $resolver = new WorkflowBindingResolver(
            $this->configFactory(['node.*' => 'simple', 'node.article' => 'editorial']),
            $this->entityTypeManager(
                ['node' => new EntityType(id: 'node', label: 'Content', class: \stdClass::class, keys: ['id' => 'nid', 'revision' => 'vid'], revisionable: true)],
                ['editorial' => $editorial, 'simple' => $simple],
            ),
        );

        $this->assertSame($editorial, $resolver->resolve('node', 'article'));
    }

    #[Test]
    public function unbound_entity_type_bundle_returns_null(): void
    {
        $resolver = new WorkflowBindingResolver(
            $this->configFactory([]),
            $this->entityTypeManager([], []),
        );

        $this->assertNull($resolver->resolve('node', 'article'));
    }

    #[Test]
    public function binding_a_non_revisionable_type_throws(): void
    {
        $editorial = new Workflow(['id' => 'editorial', 'label' => 'Editorial']);
        $resolver = new WorkflowBindingResolver(
            $this->configFactory(['note.note' => 'editorial']),
            $this->entityTypeManager(
                ['note' => new EntityType(id: 'note', label: 'Note', class: \stdClass::class, keys: ['id' => 'id'], revisionable: false)],
                ['editorial' => $editorial],
            ),
        );

        $this->expectException(\RuntimeException::class);
        $resolver->resolve('note', 'note');
    }

    #[Test]
    public function binding_a_revisionable_and_translatable_type_throws(): void
    {
        // CW-v1 option-1 (#1920 PR-2, design §1): two-axis (revisionable +
        // translatable) entity types cannot be bound at all — per-translation
        // workflow state is a post-v1 stage; silently skipping discipline
        // would reintroduce the finding-#11 draft leak.
        $editorial = new Workflow(['id' => 'editorial', 'label' => 'Editorial']);
        $resolver = new WorkflowBindingResolver(
            $this->configFactory(['page.page' => 'editorial']),
            $this->entityTypeManager(
                ['page' => new EntityType(id: 'page', label: 'Page', class: WorkflowBindingResolverTwoAxisStub::class, keys: ['id' => 'id', 'revision' => 'vid', 'langcode' => 'langcode', 'default_langcode' => 'default_langcode'], revisionable: true, translatable: true)],
                ['editorial' => $editorial],
            ),
        );

        $this->expectException(\RuntimeException::class);
        $resolver->resolve('page', 'page');
    }

    #[Test]
    public function binding_an_unknown_workflow_id_throws(): void
    {
        $resolver = new WorkflowBindingResolver(
            $this->configFactory(['node.article' => 'nonexistent']),
            $this->entityTypeManager(
                ['node' => new EntityType(id: 'node', label: 'Content', class: \stdClass::class, keys: ['id' => 'nid', 'revision' => 'vid'], revisionable: true)],
                [],
            ),
        );

        $this->expectException(\RuntimeException::class);
        $resolver->resolve('node', 'article');
    }
}
