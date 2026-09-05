<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Publishing\ContentPublicationTransitionerInterface;
use Waaseyaa\Workflows\Config\WorkflowAssignmentsConfig;
use Waaseyaa\Workflows\Read\ActiveWorkflows;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * @covers \Waaseyaa\Workflows\WorkflowServiceProvider
 */
#[CoversClass(WorkflowServiceProvider::class)]
final class WorkflowServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_workflow(): void
    {
        $provider = new WorkflowServiceProvider();
        $provider->register();

        $entityTypes = $provider->getEntityTypes();

        $this->assertCount(1, $entityTypes);
        $this->assertSame('workflow', $entityTypes[0]->id());
        $this->assertSame(Workflow::class, $entityTypes[0]->getClass());
        $this->assertArrayHasKey(ContentPublicationTransitionerInterface::class, $provider->getBindings());
    }

    #[Test]
    public function boot_registers_the_assignment_schema_on_the_shared_authority_registry(): void
    {
        $registry = new ConfigSchemaRegistry();
        $entityTypes = new EntityTypeManager(new SymfonyEventDispatcherAdapter());
        $entityTypes->registerEntityType(new EntityType(
            id: 'note',
            label: 'Note',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            revisionable: false,
        ));
        $provider = new WorkflowServiceProvider();
        $provider->setKernelServices(new class ($registry, $entityTypes) implements KernelServicesInterface {
            public function __construct(
                private readonly ConfigSchemaRegistry $registry,
                private readonly EntityTypeManagerInterface $entityTypes,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    ConfigSchemaRegistry::class => $this->registry,
                    EntityTypeManagerInterface::class => $this->entityTypes,
                    default => null,
                };
            }
        });
        $provider->register();

        $provider->boot();

        $registration = $registry->get(
            WorkflowAssignmentsConfig::CONFIG_NAME,
            WorkflowAssignmentsConfig::SCHEMA_VERSION,
        );
        self::assertNotNull($registration);
        self::assertSame(WorkflowAssignmentsConfig::OWNER_PACKAGE, $registration->ownerPackage);
        $registry->freeze();
        $violations = $registry->semanticViolations(
            WorkflowAssignmentsConfig::CONFIG_NAME,
            WorkflowAssignmentsConfig::SCHEMA_VERSION,
            ['note.note' => 'editorial'],
        );
        self::assertCount(1, $violations);
        self::assertStringContainsString('not revisionable', $violations[0]->message);
    }

    #[Test]
    public function boot_without_configuration_authority_does_not_manufacture_a_registry(): void
    {
        $provider = new WorkflowServiceProvider();
        $provider->register();

        $provider->boot();

        self::addToAssertionCount(1);
    }

    #[Test]
    public function boot_refuses_to_register_the_assignment_schema_without_semantic_authority(): void
    {
        $registry = new ConfigSchemaRegistry();
        $provider = new WorkflowServiceProvider();
        $provider->setKernelServices(new class ($registry) implements KernelServicesInterface {
            public function __construct(private readonly ConfigSchemaRegistry $registry) {}

            public function get(string $abstract): ?object
            {
                return $abstract === ConfigSchemaRegistry::class ? $this->registry : null;
            }
        });
        $provider->register();

        try {
            $provider->boot();
            self::fail('Boot must refuse a configuration authority it cannot semantically guard.');
        } catch (\LogicException $refusal) {
            self::assertStringContainsString('semantic', $refusal->getMessage());
        }

        self::assertNull($registry->get(
            WorkflowAssignmentsConfig::CONFIG_NAME,
            WorkflowAssignmentsConfig::SCHEMA_VERSION,
        ), 'A structurally-only schema must never reach the trusted registry.');
    }

    #[Test]
    public function registers_the_active_workflows_reader_and_resolves_the_bound_workflow(): void
    {
        // #2835: proves the singleton bound in register() is real, end to
        // end — not just present as a key in getBindings().
        $editorial = new Workflow(['id' => 'editorial', 'label' => 'Editorial']);
        $configFactory = new class (['node.article' => 'editorial']) implements ConfigFactoryInterface {
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
        $entityTypes = new class ($editorial) implements EntityTypeManagerInterface {
            public function __construct(private readonly Workflow $editorial) {}

            public function getDefinition(string $entityTypeId): \Waaseyaa\Entity\EntityTypeInterface { throw new \LogicException('not needed'); }
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(\Waaseyaa\Entity\EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(\Waaseyaa\Entity\EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return false; }
            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed'); }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                $editorial = $this->editorial;

                return new class ($editorial) implements EntityRepositoryInterface {
                    public function __construct(private readonly Workflow $editorial) {}

                    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
                    public function find(int|string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $id === 'editorial' ? $this->editorial : null; }
                    public function loadWorkingCopy(int|string $id): ?EntityInterface { return $this->find($id); }
                    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return in_array('editorial', $ids, true) ? [$this->editorial] : []; }
                    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
                    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }
                    public function save(EntityInterface $entity, bool $validate = true): int { throw new \LogicException('not needed'); }
                    public function delete(EntityInterface $entity): void {}
                    public function exists(int|string $id): bool { return $id === 'editorial'; }
                    public function count(array $criteria = []): int { return 1; }
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

        $provider = new WorkflowServiceProvider();
        $provider->setKernelServices(new class ($configFactory, $entityTypes) implements KernelServicesInterface {
            public function __construct(
                private readonly ConfigFactoryInterface $configFactory,
                private readonly EntityTypeManagerInterface $entityTypes,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    ConfigFactoryInterface::class => $this->configFactory,
                    EntityTypeManagerInterface::class => $this->entityTypes,
                    default => null,
                };
            }
        });
        $provider->register();

        self::assertArrayHasKey(ActiveWorkflows::class, $provider->getBindings());

        $reader = $provider->resolve(ActiveWorkflows::class);
        self::assertInstanceOf(ActiveWorkflows::class, $reader);
        self::assertSame([$editorial], $reader->all());
    }
}
