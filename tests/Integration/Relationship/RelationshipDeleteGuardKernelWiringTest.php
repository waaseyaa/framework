<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Relationship;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\EntityStorage\Exception\EntityMutationConflictException;
use Waaseyaa\EntityStorage\Testing\EntityMutationAuthoritySchema;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\EntityTypeManagerFactory;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Relationship\RelationshipDeleteGuardListener;
use Waaseyaa\Relationship\RelationshipServiceProvider;

/**
 * #2728, acceptance criterion 4 — production-composition proof that the
 * REGISTERED RelationshipDeleteGuardListener actually blocks a delete issued
 * through a repository built by EntityTypeManagerFactory, and that its refusal
 * rolls back the row AND the mutation-authority tombstone on both the single
 * and the batch delete path.
 *
 * Directly invoking the listener (as the package unit tests do) proves nothing
 * about timing: before #2728 doDelete() handed PRE_DELETE to the UnitOfWork
 * buffer, so the guard refused only after the delete had already committed.
 *
 * The provider is wired through the kernel-services bus keyed on the
 * Symfony-CONTRACTS dispatcher FQCN, because resolving the foundation FQCN
 * returns null and would silently skip registration.
 */
#[CoversNothing]
final class RelationshipDeleteGuardKernelWiringTest extends TestCase
{
    private DBALDatabase $database;

    private EntityTypeManager $manager;

    private SymfonyEventDispatcherAdapter $dispatcher;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->dispatcher = new SymfonyEventDispatcherAdapter();
        $this->manager = new EntityTypeManagerFactory()->build(
            database: $this->database,
            dispatcher: $this->dispatcher,
            fieldRegistry: new FieldDefinitionRegistry(),
            logger: new NullLogger(),
            accessHandlerResolver: static fn() => null,
            communityScoreResolver: static fn($definition) => null,
            accountContextAttacher: static function (object $repository): void {},
            fieldReadScope: new AccountFieldReadScope(),
        );

        $provider = new RelationshipServiceProvider();
        $provider->setKernelServices($this->kernelServices([
            \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
            EntityTypeManager::class => $this->manager,
        ]));
        $provider->register();
        foreach ($provider->getEntityTypes() as $entityType) {
            $this->manager->registerEntityType($entityType);
        }
        $this->manager->registerEntityType(EntityType::fromClass(TestStorageEntity::class));
        $provider->boot();

        new EntitySchemaSyncRunner($this->database, $this->manager->getFieldRegistry())
            ->run($this->manager->getDefinitions());
        EntityMutationAuthoritySchema::ensure($this->database);

        // Anti-fail-open preamble: the guard silently returns when the
        // 'relationship' definition is missing, so a composition mistake would
        // make every assertion below pass for the wrong reason.
        self::assertTrue($this->manager->hasDefinition('relationship'));
        $listeners = $this->dispatcher->getListeners(EntityEvents::PRE_DELETE->value);
        self::assertNotEmpty($listeners, 'RelationshipServiceProvider::boot() must register the delete guard.');
        self::assertInstanceOf(RelationshipDeleteGuardListener::class, $listeners[0]);
    }

    #[Test]
    public function allowedDeleteOfAnUnlinkedEndpointCommitsAndLeavesTheEdgeIntact(): void
    {
        [$linked, $unlinked] = $this->seed();
        $authorityBefore = $this->authorityRow('2');
        self::assertIsArray($authorityBefore);

        $this->repository()->delete($unlinked);

        self::assertSame(0, $this->rowCount('2'));
        $after = $this->authorityRow('2');
        self::assertIsArray($after);
        self::assertSame((int) $authorityBefore['aggregate_version'] + 1, (int) $after['aggregate_version']);
        self::assertSame('tombstone', $after['lifecycle_state']);
        self::assertSame(1, $this->relationshipRowCount());
        self::assertSame(1, $this->rowCount('1'));
        self::assertNotNull($linked);
    }

    #[Test]
    public function forbiddenDeleteOfALinkedEndpointRollsBackTheRowAndTheTombstone(): void
    {
        [$linked] = $this->seed();
        $authorityBefore = $this->authorityRow('1');
        self::assertIsArray($authorityBefore);
        $tokenVersion = $linked->mutationToken()?->aggregateVersion;

        try {
            $this->repository()->delete($linked);
            self::fail('The registered delete guard must refuse a linked endpoint.');
        } catch (\RuntimeException $e) {
            // Never assert on the bare type: MissingEntityMutationTokenException
            // is also a RuntimeException and would masquerade as a guard block.
            self::assertStringContainsString('Safe-delete blocked for', $e->getMessage());
        }

        self::assertSame(1, $this->rowCount('1'), 'A refused delete must not remove the row.');
        self::assertSame($authorityBefore, $this->authorityRow('1'), 'The tombstone must roll back with the row.');
        self::assertSame('active', (string) $this->authorityRow('1')['lifecycle_state']);
        self::assertSame(1, $this->relationshipRowCount());
        self::assertSame($tokenVersion, (int) $authorityBefore['aggregate_version']);
    }

    #[Test]
    public function batchRefusalOnTheSecondEndpointRollsBackTheFirstEndpointToo(): void
    {
        [$linked, $unlinked] = $this->seed();
        $authorityOne = $this->authorityRow('1');
        $authorityTwo = $this->authorityRow('2');
        self::assertIsArray($authorityOne);
        self::assertIsArray($authorityTwo);

        try {
            $this->repository()->deleteMany([$unlinked, $linked]);
            self::fail('The registered delete guard must refuse the linked endpoint mid-batch.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Safe-delete blocked for', $e->getMessage());
        }

        self::assertSame(1, $this->rowCount('1'));
        self::assertSame(1, $this->rowCount('2'), 'The first entity of the batch must be restored.');
        self::assertSame($authorityOne, $this->authorityRow('1'));
        self::assertSame($authorityTwo, $this->authorityRow('2'), 'The first entity tombstone must roll back too.');
        self::assertSame('active', (string) $this->authorityRow('2')['lifecycle_state']);
        self::assertSame(1, $this->relationshipRowCount());
    }

    #[Test]
    public function staleMutationTokenDeleteIsRefusedAndLeavesTheRowIntact(): void
    {
        $this->seed();
        $repository = $this->repository();
        $stale = $repository->find('2');
        $winner = $repository->find('2');
        self::assertNotNull($stale);
        self::assertNotNull($winner);

        $winner->set('label', 'winner');
        $repository->save($winner, validate: false);

        try {
            $repository->delete($stale);
            self::fail('A stale mutation token must be refused.');
        } catch (EntityMutationConflictException) {
            self::assertSame(1, $this->rowCount('2'));
            self::assertSame('active', (string) $this->authorityRow('2')['lifecycle_state']);
        }
    }

    /**
     * @return array{EntityInterface, EntityInterface}
     */
    private function seed(): array
    {
        $repository = $this->repository();
        $linked = $repository->create(['id' => '1', 'uuid' => 'endpoint-1', 'label' => 'Linked']);
        $unlinked = $repository->create(['id' => '2', 'uuid' => 'endpoint-2', 'label' => 'Unlinked']);
        $target = $repository->create(['id' => '3', 'uuid' => 'endpoint-3', 'label' => 'EdgeTarget']);
        $repository->save($linked, validate: false);
        $repository->save($unlinked, validate: false);
        $repository->save($target, validate: false);

        $relationships = $this->manager->getRepository('relationship');
        $edge = $relationships->create([
            'rid' => '1',
            'uuid' => 'edge-1',
            'relationship_type' => 'related',
            'from_entity_type' => 'test_entity',
            'from_entity_id' => '1',
            'to_entity_type' => 'test_entity',
            'to_entity_id' => '3',
            'directionality' => 'directed',
            'status' => true,
        ]);
        $relationships->save($edge, validate: false);

        self::assertSame(1, $this->relationshipRowCount(), 'The guard needs a live edge row to refuse against.');

        return [$linked, $unlinked];
    }

    private function repository(): \Waaseyaa\EntityStorage\EntityRepository
    {
        $repository = $this->manager->getRepository('test_entity');
        self::assertInstanceOf(\Waaseyaa\EntityStorage\EntityRepository::class, $repository);

        return $repository;
    }

    private function rowCount(string $id): int
    {
        return (int) $this->database->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM test_entity WHERE id = ?', [$id]);
    }

    private function relationshipRowCount(): int
    {
        return (int) $this->database->getConnection()->fetchOne('SELECT COUNT(*) FROM relationship');
    }

    /**
     * @return array<string, mixed>|false
     */
    private function authorityRow(string $entityId): array|false
    {
        return $this->database->getConnection()->fetchAssociative(
            'SELECT aggregate_version, mutation_tag, lifecycle_state FROM waaseyaa_entity_mutation_authority'
            . " WHERE entity_type = 'test_entity' AND entity_id = ?",
            [$entityId],
        );
    }

    /**
     * @param array<string, object> $services
     */
    private function kernelServices(array $services): KernelServicesInterface
    {
        return new class ($services) implements KernelServicesInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services) {}

            public function get(string $abstract): ?object
            {
                return $this->services[$abstract] ?? null;
            }
        };
    }
}
