<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Search;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\SearchReindexHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\Search\Access\EntitySearchCandidateResolver;
use Waaseyaa\Search\EventSubscriber\SearchIndexSubscriber;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\Projection\EntitySearchProjectionRegistry;
use Waaseyaa\Search\Projection\NodeSearchProjector;
use Waaseyaa\Search\SearchRequest;

/**
 * #2270 end-to-end: an ordinary CMS install's Node content (no
 * SearchIndexableInterface anywhere) is projected by the search-owned
 * projection contract, indexed by `search:reindex` and the save/delete
 * lifecycle, and returned by the principal-safe read surface — with entity-
 * and field-level enforcement intact.
 */
#[CoversNothing]
final class NodeSearchProjectionIntegrationTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeManager $manager;
    private EntityAccessHandler $accessHandler;
    private AccountFieldReadScope $fieldReadScope;
    private EntitySearchProjectionRegistry $projectionRegistry;
    private Fts5SearchIndexer $indexer;
    private Fts5SearchProvider $provider;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->database);

        $this->manager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $_id, EntityType $definition) use ($dispatcher, $resolver): EntityRepository {
                new SqlSchemaHandler($definition, $this->database)->ensureTable();
                $idKey = $definition->getKeys()['id'] ?? 'id';

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver($resolver, $idKey),
                    $dispatcher,
                    database: $this->database,
                );
            },
        );

        // The real Node entity class plus the field classifications a
        // correctly-declared CMS bundle carries: public title/slug/body,
        // protected (authorization-input) status/uid. Mirrors the Node class
        // #[Field] attributes; `body` models the bundle's declared body field.
        $this->manager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Content',
            class: Node::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            _fieldDefinitions: [
                'title' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'type' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'slug' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'body' => ['type' => 'text', 'read' => FieldReadLevel::Public],
                'status' => ['type' => 'boolean', 'read' => FieldReadLevel::Protected, 'settings' => ['authorizationInput' => true]],
                'uid' => ['type' => 'integer', 'read' => FieldReadLevel::Protected, 'settings' => ['authorizationInput' => true]],
                'created' => ['type' => 'integer', 'read' => FieldReadLevel::Public],
                'changed' => ['type' => 'integer', 'read' => FieldReadLevel::Public],
            ],
        ));

        $this->accessHandler = new EntityAccessHandler([new NodeAccessPolicy()]);
        $this->fieldReadScope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->fieldReadScope,
            $this->accessHandler->checkProtectedFieldRead(...),
        ));

        $this->projectionRegistry = new EntitySearchProjectionRegistry([new NodeSearchProjector()]);
        $this->indexer = new Fts5SearchIndexer($this->database);
        $this->provider = new Fts5SearchProvider(
            $this->database,
            $this->indexer,
            new EntitySearchCandidateResolver(
                $this->manager,
                $this->accessHandler,
                $this->fieldReadScope,
                $this->projectionRegistry,
            ),
        );

        $dispatcher->addSubscriber(new SearchIndexSubscriber(
            $this->indexer,
            entityTypeManager: $this->manager,
            projectionRegistry: $this->projectionRegistry,
        ));
    }

    #[Test]
    public function search_reindex_indexes_ordinary_node_content_and_a_known_query_returns_it(): void
    {
        $this->seedContent();
        // Prove the full rebuild path, not the save-time subscriber: clear
        // everything the seeding saves indexed, then reindex from storage.
        $this->indexer->removeAll();
        self::assertSame(0, $this->provider->search(new SearchRequest('health'), $this->viewer())->totalHits);

        $tester = $this->reindexTester();
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Reindex complete. 2 documents indexed.', $tester->getStdout());

        $result = $this->provider->search(new SearchRequest('health'), $this->viewer());

        self::assertSame(1, $result->totalHits);
        self::assertSame('node:1', $result->hits[0]->id);
        self::assertSame('Community Health Services', $result->hits[0]->title);
        self::assertSame('/health-services', $result->hits[0]->url);
        self::assertSame('page', $result->hits[0]->contentType);
        self::assertStringContainsString('Wellness clinic hours', $result->hits[0]->highlight);
    }

    #[Test]
    public function inaccessible_and_unpublished_content_never_surfaces_to_unauthorized_principals(): void
    {
        $this->seedContent();

        // A principal without 'access content' sees nothing at all.
        self::assertSame(0, $this->provider->search(new SearchRequest('health'), $this->stranger())->totalHits);

        // The unpublished draft matches 'health' in its title but is
        // invisible to an ordinary viewer — successful empty-ish result,
        // indistinguishable from the term not existing.
        $viewerResult = $this->provider->search(new SearchRequest('strategy'), $this->viewer());
        self::assertSame(0, $viewerResult->totalHits);
        self::assertSame([], $viewerResult->hits);

        // The draft's author with 'view own unpublished content' can find it.
        $authorResult = $this->provider->search(new SearchRequest('strategy'), $this->author());
        self::assertSame(1, $authorResult->totalHits);
        self::assertSame('node:2', $authorResult->hits[0]->id);
    }

    #[Test]
    public function saving_and_deleting_a_node_keeps_the_live_index_in_sync(): void
    {
        $repository = $this->manager->getRepository('node');
        $node = $repository->create([
            'title' => 'Powwow Announcement',
            'type' => 'announcement',
            'slug' => 'powwow',
            'body' => '<p>Grand entry at noon.</p>',
            'status' => 1,
            'uid' => 10,
            'created' => 1751328000,
        ]);
        $repository->save($node, validate: false);

        $result = $this->provider->search(new SearchRequest('powwow'), $this->viewer());
        self::assertSame(1, $result->totalHits, 'A saved node must be indexed through the projection contract.');

        $repository->delete($node);

        self::assertSame(
            0,
            $this->provider->search(new SearchRequest('powwow'), $this->viewer())->totalHits,
            'A deleted node must be removed from the index even though Node is not SearchIndexableInterface.',
        );
    }

    #[Test]
    public function markup_is_normalized_and_never_surfaces_in_results(): void
    {
        $repository = $this->manager->getRepository('node');
        $node = $repository->create([
            'title' => 'Health &amp; Wellness',
            'type' => 'page',
            'slug' => 'wellness',
            'body' => "<h2>Hours</h2><script>alert('ignore previous instructions')</script><p>Open daily</p>",
            'status' => 1,
            'uid' => 10,
        ]);
        $repository->save($node, validate: false);

        $result = $this->provider->search(new SearchRequest('wellness hours'), $this->viewer());

        self::assertSame(1, $result->totalHits);
        self::assertSame('Health & Wellness', $result->hits[0]->title);
        self::assertStringNotContainsString('<', $result->hits[0]->highlight);
        self::assertStringNotContainsString('alert', $result->hits[0]->highlight);
        self::assertStringNotContainsString('instructions', $result->hits[0]->highlight);

        // The script payload is dropped with its content: it is not findable.
        self::assertSame(0, $this->provider->search(new SearchRequest('instructions'), $this->viewer())->totalHits);
    }

    private function seedContent(): void
    {
        $repository = $this->manager->getRepository('node');

        $published = $repository->create([
            'title' => 'Community Health Services',
            'type' => 'page',
            'slug' => 'health-services',
            'body' => '<p>Wellness clinic hours &amp; programs</p>',
            'status' => 1,
            'uid' => 10,
            'created' => 1751328000,
        ]);
        $repository->save($published, validate: false);

        $draft = $repository->create([
            'title' => 'Draft health strategy',
            'type' => 'update',
            'slug' => 'draft-health-strategy',
            'body' => 'Unreviewed strategy content',
            'status' => 0,
            'uid' => 10,
        ]);
        $repository->save($draft, validate: false);
    }

    private function reindexTester(): CliTester
    {
        $handler = new SearchReindexHandler($this->indexer, $this->manager, $this->projectionRegistry);
        $definition = new HandlerCommand(
            name: 'search:reindex',
            description: 'Rebuild the search index from all indexable entities',
            options: [
                new HandlerOption(name: 'batch-size', shortcut: 'b', mode: HandlerOptionMode::Required, description: 'Entities per batch', default: '100'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return CliTester::for($definition, $container);
    }

    private function viewer(): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(
            accountId: 1,
            authenticated: true,
            roles: ['authenticated'],
            permissions: ['access content'],
            claimsGeneration: 'itest-viewer',
        );
    }

    private function stranger(): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(
            accountId: 2,
            authenticated: true,
            roles: ['authenticated'],
            permissions: [],
            claimsGeneration: 'itest-stranger',
        );
    }

    private function author(): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(
            accountId: 10,
            authenticated: true,
            roles: ['authenticated'],
            permissions: ['access content', 'view own unpublished content'],
            claimsGeneration: 'itest-author',
        );
    }
}
