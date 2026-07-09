<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeServiceProvider;
use Waaseyaa\Node\NodeType;

/**
 * REQUIRED wiring regression test (CW-v1 WP-2 Task 2.3,
 * docs/specs/content-workflow.md): a unit test on
 * {@see \Waaseyaa\Node\Listener\NodeRevisionDefaultListener} alone does not
 * prove the per-bundle `NodeType::isNewRevision()` knob actually changes
 * what gets written — only a real kernel-dispatched save through the real
 * {@see EntityRepository}, with {@see NodeServiceProvider::boot()} doing the
 * ACTUAL listener registration on the SAME dispatcher instance the
 * repository uses, proves the wiring end to end. Mirrors
 * `Waaseyaa\Workflows\Tests\Integration\GuardWiringTest` (same union pattern:
 * `RelationshipServiceProviderTest`-style unit-level wiring assertion +
 * `NodeRevisionStorageTest`-style real SQLite revision-row counting).
 *
 * Every collaborator here (dispatcher, EntityTypeManager, node/node_type
 * storage) is the real production class — nothing about the listener itself
 * is mocked or invoked directly.
 */
#[CoversNothing]
final class NodeRevisionDefaultWiringTest extends TestCase
{
    #[Test]
    public function a_bundle_with_new_revision_false_updates_in_place_with_no_new_revision_row(): void
    {
        [$entityTypeManager, $db] = $this->bootWiredProvider();

        $nodeTypeRepository = $entityTypeManager->getRepository('node_type');
        $nodeTypeRepository->save(new NodeType(['type' => 'note', 'name' => 'Note', 'new_revision' => false]));

        $nodeRepository = $entityTypeManager->getRepository('node');
        $node = new Node(['title' => 'Hello', 'type' => 'note', 'slug' => 'hello']);
        $node->enforceIsNew();
        $nodeRepository->save($node);

        $this->assertSame(1, $this->revisionRowCount($db), 'The first save always writes revision 1.');

        $loaded = $nodeRepository->find((string) $node->id());
        \assert($loaded instanceof Node);
        $loaded->setTitle('Hello, updated');
        $nodeRepository->save($loaded);

        $this->assertSame(
            1,
            $this->revisionRowCount($db),
            'new_revision: false must update in place — no second revision row.',
        );

        $stored = $nodeRepository->find((string) $node->id());
        \assert($stored instanceof Node);
        $this->assertSame('Hello, updated', $stored->getTitle(), 'The in-place update must still be visible.');
    }

    #[Test]
    public function a_bundle_with_no_node_type_row_falls_back_to_the_entity_type_default_and_gets_a_new_revision_per_save(): void
    {
        [$entityTypeManager, $db] = $this->bootWiredProvider();

        // Deliberately no NodeType row saved for 'article' — the missing/
        // unloadable-NodeType path.
        $nodeRepository = $entityTypeManager->getRepository('node');
        $node = new Node(['title' => 'Hello', 'type' => 'article', 'slug' => 'hello']);
        $node->enforceIsNew();
        $nodeRepository->save($node);

        $this->assertSame(1, $this->revisionRowCount($db));

        $loaded = $nodeRepository->find((string) $node->id());
        \assert($loaded instanceof Node);
        $loaded->setTitle('Hello, updated');
        $nodeRepository->save($loaded);

        $this->assertSame(
            2,
            $this->revisionRowCount($db),
            'No bundle config -> falls back to revisionDefault: true -> a new revision every save.',
        );
    }

    #[Test]
    public function an_explicit_earlier_decision_survives_the_bundle_default_listener(): void
    {
        [$entityTypeManager, $db] = $this->bootWiredProvider();

        $nodeTypeRepository = $entityTypeManager->getRepository('node_type');
        $nodeTypeRepository->save(new NodeType(['type' => 'note', 'name' => 'Note', 'new_revision' => false]));

        $nodeRepository = $entityTypeManager->getRepository('node');
        $node = new Node(['title' => 'Hello', 'type' => 'note', 'slug' => 'hello']);
        $node->enforceIsNew();
        $nodeRepository->save($node);

        $this->assertSame(1, $this->revisionRowCount($db));

        // Simulates an earlier PRE_SAVE actor (e.g. TransitionService)
        // already deciding this save must cut a revision, overriding the
        // bundle's new_revision: false.
        $loaded = $nodeRepository->find((string) $node->id());
        \assert($loaded instanceof Node);
        $loaded->setTitle('Hello, forced revision');
        $loaded->setNewRevision(true);
        $nodeRepository->save($loaded);

        $this->assertSame(
            2,
            $this->revisionRowCount($db),
            'An explicit setNewRevision(true) made before the listener runs must survive it.',
        );
    }

    private function revisionRowCount(DBALDatabase $db): int
    {
        foreach ($db->query('SELECT COUNT(*) AS c FROM node_revision') as $row) {
            return (int) $row['c'];
        }

        return -1;
    }

    /**
     * Wires a real dispatcher and a real SQLite-backed EntityTypeManager
     * (both `node` and `node_type`), then boots a REAL NodeServiceProvider
     * against a stub kernel-services bus that serves both under the exact
     * FQCNs production code resolves them by. The SAME dispatcher instance
     * is fed to both `NodeServiceProvider::boot()`'s `addListener()` call
     * and the `EntityRepository` instances that perform saves, so a real
     * save fires the REAL listener, not a stand-in.
     *
     * @return array{0: EntityTypeManagerInterface, 1: DBALDatabase}
     */
    private function bootWiredProvider(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $db): EntityRepositoryInterface {
            $schemaHandler = new SqlSchemaHandler($definition, $db);
            $schemaHandler->ensureTable();
            if ($definition->isRevisionable()) {
                $schemaHandler->ensureRevisionTable();
            }

            $resolver = new SingleConnectionResolver($db);

            return new EntityRepository(
                $definition,
                new SqlStorageDriver($resolver, $definition->getKeys()['id']),
                $dispatcher,
                $definition->isRevisionable() ? new RevisionableStorageDriver($resolver, $definition) : null,
                $db,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory);

        $provider = new NodeServiceProvider();

        $kernelServices = new class ($dispatcher, $entityTypeManager) implements KernelServicesInterface {
            public function __construct(
                private readonly SymfonyEventDispatcherAdapter $dispatcher,
                private readonly EntityTypeManager $entityTypeManager,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    EntityTypeManager::class, EntityTypeManagerInterface::class => $this->entityTypeManager,
                    default => null,
                };
            }
        };

        $provider->setKernelServices($kernelServices);
        $provider->register();

        foreach ($provider->getEntityTypes() as $entityType) {
            $entityTypeManager->registerEntityType($entityType);
        }

        // The subject under test: the REAL provider, booted against the REAL
        // kernel-services bus. boot() wires NodeRevisionDefaultListener onto
        // $dispatcher — the SAME instance the repositoryFactory above
        // dispatches PRE_SAVE through.
        $provider->boot();

        return [$entityTypeManager, $db];
    }
}
