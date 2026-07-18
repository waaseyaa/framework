<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase12;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Pipeline\Pipeline;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Media\Media;
use Waaseyaa\Media\MediaType;
use Waaseyaa\Menu\Menu;
use Waaseyaa\Menu\MenuLink;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Path\PathAlias;
use Waaseyaa\Taxonomy\Term;
use Waaseyaa\Taxonomy\Vocabulary;
use Waaseyaa\Tests\Support\AuthorizationPrincipalFactory;
use Waaseyaa\Tests\Support\ProtectedFieldRead;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;
use Waaseyaa\Workflows\Workflow;

/**
 * Integration tests for the entity type registrations introduced in PR #9.
 *
 * Anchors the user entity key correction ('id' => 'uid') and verifies that
 * each newly registered entity type can be persisted and loaded via
 * EntityRepository backed by an in-memory SQLite database.
 */
#[CoversNothing]
final class EntityTypeRegistrationTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            null,
            // C-22 WP4: legacy SqlEntityStorage engine is deleted; persistence now
            // goes through the canonical EntityRepository.
            function (string $entityTypeId, EntityTypeInterface $def) use ($dispatcher): EntityRepository {
                $schema = new SqlSchemaHandler($def, $this->database);
                $schema->ensureTable();

                $idKey = $def->getKeys()['id'] ?? 'id';
                $resolver = new SingleConnectionResolver($this->database);

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create($def, new SqlStorageDriver($resolver, $idKey), $dispatcher);
            },
        );

        // Register all entity types from public/index.php, covering every layer
        // added in the PR so this test will fail if any registration drifts.

        // Layer 1: Core Data.
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        // Layer 2: Content Types.
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Content',
            class: Node::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'node_type',
            label: 'Content Type',
            class: NodeType::class,
            keys: ['id' => 'type', 'label' => 'name'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'taxonomy_term',
            label: 'Taxonomy Term',
            class: Term::class,
            keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name', 'bundle' => 'vid'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'taxonomy_vocabulary',
            label: 'Vocabulary',
            class: Vocabulary::class,
            keys: ['id' => 'vid', 'label' => 'name'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'media',
            label: 'Media',
            class: Media::class,
            keys: ['id' => 'mid', 'uuid' => 'uuid', 'label' => 'name', 'bundle' => 'bundle'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'media_type',
            label: 'Media Type',
            class: MediaType::class,
            keys: ['id' => 'id', 'label' => 'label'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'path_alias',
            label: 'Path Alias',
            class: PathAlias::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'alias', 'langcode' => 'langcode'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'menu',
            label: 'Menu',
            class: Menu::class,
            keys: ['id' => 'id', 'label' => 'label'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'menu_link',
            label: 'Menu Link',
            class: MenuLink::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'menu_name'],
        ));

        // Layer 3: Services.
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'workflow',
            label: 'Workflow',
            class: Workflow::class,
            keys: ['id' => 'id', 'label' => 'label'],
        ));

        // Layer 5: AI.
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'pipeline',
            label: 'Pipeline',
            class: Pipeline::class,
            keys: ['id' => 'id', 'label' => 'label'],
        ));
    }

    /**
     * The user entity key was corrected from 'id' => 'id' to 'id' => 'uid'.
     * This test anchors that fix: a User round-trips through EntityRepository
     * and is loadable by its uid.
     */
    #[Test]
    public function user_entity_persists_and_loads_by_uid(): void
    {
        $repository = $this->entityTypeManager->getRepository('user');

        $user = new User(['name' => 'alice', 'mail' => 'alice@example.com']);
        $repository->save($user, validate: false);

        $uid = $user->id();
        $this->assertGreaterThan(0, $uid, 'User uid must be a positive integer after save.');

        $loaded = $repository->find((string) $uid);

        $this->assertNotNull($loaded, 'User must be loadable by uid.');
        $this->assertSame($uid, $loaded->id());
        $admin = AuthorizationPrincipalFactory::authenticated(permissions: ['administer users']);
        $this->assertSame('alice', ProtectedFieldRead::run([new UserAccessPolicy()], $admin, fn() => $loaded->get('name')));
    }

    /**
     * All 12 entity type IDs registered in index.php must be resolvable
     * to storage via EntityTypeManager.
     */
    #[Test]
    public function all_registered_entity_type_ids_are_present(): void
    {
        $expected = [
            'user', 'node', 'node_type', 'taxonomy_term', 'taxonomy_vocabulary',
            'media', 'media_type', 'path_alias', 'menu', 'menu_link',
            'workflow', 'pipeline',
        ];

        $definitions = $this->entityTypeManager->getDefinitions();
        $registered = array_keys($definitions);

        foreach ($expected as $id) {
            $this->assertContains($id, $registered, "Entity type '$id' must be registered.");
        }
    }

    /**
     * Integer-keyed content entities (node, taxonomy_term, media) are
     * saved and loaded back correctly with auto-assigned IDs.
     */
    #[Test]
    public function integer_keyed_entities_persist_and_load(): void
    {
        $nodeRepository = $this->entityTypeManager->getRepository('node');
        $node = new Node(['title' => 'Hello World', 'type' => 'article']);
        $nodeRepository->save($node, validate: false);
        $loaded = $nodeRepository->find((string) $node->id());
        $this->assertNotNull($loaded);
        $this->assertSame('Hello World', $loaded->get('title'));

        $termRepository = $this->entityTypeManager->getRepository('taxonomy_term');
        $term = new Term(['name' => 'PHP', 'vid' => 'tags']);
        $termRepository->save($term, validate: false);
        $loaded = $termRepository->find((string) $term->id());
        $this->assertNotNull($loaded);
        $this->assertSame('PHP', $loaded->get('name'));

        $mediaRepository = $this->entityTypeManager->getRepository('media');
        $media = new Media(['name' => 'Photo', 'bundle' => 'image']);
        $mediaRepository->save($media, validate: false);
        $loaded = $mediaRepository->find((string) $media->id());
        $this->assertNotNull($loaded);
        $this->assertSame('Photo', $loaded->get('name'));
    }

    /**
     * Path aliases use integer IDs (auto-assigned) and are stored via
     * EntityRepository like other content entities.
     */
    #[Test]
    public function path_alias_persists_and_loads(): void
    {
        $repository = $this->entityTypeManager->getRepository('path_alias');

        $alias = new PathAlias([
            'path' => '/node/1',
            'alias' => '/hello-world',
            'langcode' => 'en',
        ]);
        $repository->save($alias, validate: false);

        $loaded = $repository->find((string) $alias->id());
        $this->assertNotNull($loaded);
        $this->assertSame('/hello-world', $loaded->get('alias'));
    }
}
