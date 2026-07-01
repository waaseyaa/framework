<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Node\Node;
use Waaseyaa\SSR\SsrPageHandler;

/**
 * #1686 — the canonical public content path must resolve `GET /{type}/{uuid}`,
 * not only `/{type}/{id}`. `SsrPageHandler` fed the raw path segment to
 * `EntityRepository::find()`, which queries the numeric `id` column only, so a
 * UUID never matched and a valid entity 404'd purely on identifier shape.
 *
 * `loadByIdOrUuid()` now resolves a UUID-shaped segment to the numeric id first;
 * `find()` stays numeric-keyed.
 */
#[CoversClass(SsrPageHandler::class)]
final class SsrPageHandlerUuidResolutionTest extends TestCase
{
    private DBALDatabase $db;
    private EntityRepository $repository;
    private EntityTypeManager $etm;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'story',
            label: 'Story',
            class: Node::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            group: 'content',
        );

        new SqlSchemaHandler($entityType, $this->db)->ensureTable();

        $dispatcher = new EventDispatcher();
        $db = $this->db;
        $resolver = new SingleConnectionResolver($this->db);
        $this->repository = new EntityRepository(
            $entityType,
            new SqlStorageDriver($resolver),
            $dispatcher,
            database: $db,
        );
        $repository = $this->repository;

        $this->etm = new EntityTypeManager(
            $dispatcher,
            null,
            // C-22 WP4: repository factory mirroring the kernel's getRepository() shape.
            static fn(string $_id, EntityTypeInterface $t): EntityRepository => $repository,
        );
        $this->etm->registerEntityType($entityType);
    }

    #[Test]
    public function resolves_a_content_entity_by_uuid_and_by_numeric_id(): void
    {
        $entity = $this->repository->create(['title' => 'Water Is Life', 'status' => true]);
        $this->repository->save($entity, validate: false);

        $id = $entity->id();
        $uuid = (string) $entity->get('uuid');
        self::assertNotSame('', $uuid, 'precondition: the saved entity has a uuid');

        // By UUID — previously a 404 (numeric load() never matched).
        $byUuid = $this->loadByIdOrUuid('story', $uuid);
        self::assertInstanceOf(EntityInterface::class, $byUuid);
        self::assertSame((string) $id, (string) $byUuid->id(), 'uuid resolves to the same entity');

        // By numeric id — the canonical path, unchanged.
        $byId = $this->loadByIdOrUuid('story', (string) $id);
        self::assertInstanceOf(EntityInterface::class, $byId);
        self::assertSame((string) $id, (string) $byId->id());
    }

    #[Test]
    public function returns_null_for_an_unknown_uuid(): void
    {
        $this->repository->save($this->repository->create(['title' => 'Seeded', 'status' => true]), validate: false);

        // Well-formed but unused UUID → no match → null (the caller renders 404,
        // which is correct: the entity genuinely does not exist).
        self::assertNull($this->loadByIdOrUuid('story', '99999999-9999-4999-8999-999999999999'));
    }

    private function loadByIdOrUuid(string $entityTypeId, string $id): ?EntityInterface
    {
        $handler = new SsrPageHandler(
            entityTypeManager: $this->etm,
            database: $this->db,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver([]),
            discoveryHandler: new DiscoveryApiHandler($this->etm, $this->db),
            projectRoot: '/tmp/test-project',
            config: [],
            accessHandler: null,
        );

        $method = new \ReflectionMethod(SsrPageHandler::class, 'loadByIdOrUuid');

        /** @var EntityInterface|null $result */
        $result = $method->invoke($handler, $entityTypeId, $id);

        return $result;
    }
}
