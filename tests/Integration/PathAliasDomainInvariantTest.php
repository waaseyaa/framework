<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\AbortOperationException;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Path\PathAlias;
use Waaseyaa\Path\PathAliasResolver;
use Waaseyaa\Path\PathAliasUniquenessListener;

/**
 * Issue #2754: `path_alias` could persist aliases that
 * {@see PathAliasResolver} will never accept. The leading-slash invariant
 * lived only in {@see PathAlias::setAlias()} — a convenience setter that
 * neither generic entity construction (JSON:API POST shape, which assigns
 * `$values` straight into entity storage) nor generic `set()` mutation
 * (JSON:API PATCH shape) calls. The uniqueness listener normalized the
 * value (NFC + trailing slash) but never checked the leading slash, so a
 * successful, validated save could create a row `PathAliasResolver::resolve()`
 * would always return null for.
 *
 * This test exercises the REAL save path end to end — a real SQLite-backed
 * {@see EntityRepository}, a real Symfony {@see EventDispatcher}, and
 * {@see PathAliasUniquenessListener} registered on {@see BeforeSaveEvent}
 * exactly as {@see \Waaseyaa\Path\PathServiceProvider::boot()} wires it —
 * proving the fix lives in the universal write boundary, not only in
 * {@see PathAlias::setAlias()}.
 */
#[CoversNothing]
final class PathAliasDomainInvariantTest extends TestCase
{
    private DBALDatabase $database;
    private EntityRepository $repository;
    private PathAliasResolver $resolver;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();

        $entityType = EntityType::fromClass(PathAlias::class, group: 'structure');

        new SqlSchemaHandler($entityType, $this->database)->ensureTable();

        $eventDispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->database);
        $database = $this->database;

        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver),
            $eventDispatcher,
            database: $database,
        );

        $entityTypeManager = new EntityTypeManager(
            $eventDispatcher,
            repositoryFactory: static fn(string $_id, EntityTypeInterface $type): EntityRepository => \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $type,
                new SqlStorageDriver($resolver),
                $eventDispatcher,
                database: $database,
            ),
        );
        $entityTypeManager->registerEntityType($entityType);

        // The exact wiring PathServiceProvider::boot() performs in a real kernel.
        $eventDispatcher->addListener(
            BeforeSaveEvent::class,
            new PathAliasUniquenessListener($entityTypeManager),
        );

        $this->resolver = new PathAliasResolver($this->repository);
    }

    private function countRows(): int
    {
        foreach ($this->database->query('SELECT COUNT(*) AS cnt FROM path_alias') as $row) {
            return (int) $row['cnt'];
        }

        return 0;
    }

    private function readStoredAlias(string $id): string
    {
        foreach ($this->database->query('SELECT alias FROM path_alias WHERE id = ' . (int) $id) as $row) {
            return (string) $row['alias'];
        }

        return '';
    }

    #[Test]
    public function genericConstructionCannotPersistAnAliasWithoutALeadingSlash(): void
    {
        // JSON:API POST shape: EntityRepository::create() assigns $values
        // straight into entity storage — no setAlias() call in the path.
        $entity = $this->repository->create([
            'path' => '/note/1',
            'alias' => 'missing-leading-slash',
            'langcode' => 'en',
        ]);

        $caught = null;
        try {
            $this->repository->save($entity, validate: false);
        } catch (AbortOperationException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            AbortOperationException::class,
            $caught,
            'a create with a leading-slash-less alias must be refused at the write boundary',
        );
        $this->assertSame(0, $this->countRows(), 'the refused create must not have persisted a row');
    }

    #[Test]
    public function genericSetPatchCannotPersistAnAliasWithoutALeadingSlash(): void
    {
        $entity = $this->repository->create(['path' => '/note/2', 'alias' => '/valid', 'langcode' => 'en']);
        $this->repository->save($entity, validate: false);
        $id = $entity->id();
        $this->assertNotNull($id);

        $loaded = $this->repository->find((string) $id);
        $this->assertInstanceOf(PathAlias::class, $loaded);

        // JSON:API PATCH shape: JsonApiController's update loop applies
        // request attributes via the generic EntityBase::set(), which
        // bypasses PathAlias::setAlias()'s leading-slash guard entirely.
        $loaded->set('alias', 'patched-without-slash');

        $caught = null;
        try {
            $this->repository->save($loaded, validate: false);
        } catch (AbortOperationException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            AbortOperationException::class,
            $caught,
            'a generic-set update to a leading-slash-less alias must be refused at the write boundary',
        );
        $this->assertSame(1, $this->countRows(), 'the refused update must leave prior database state unchanged');
        $this->assertSame(
            '/valid',
            $this->readStoredAlias((string) $id),
            'the previously persisted row must be untouched by the refused write',
        );
    }

    #[Test]
    public function everyPersistedAliasResolvesThroughPathAliasResolver(): void
    {
        // The affirmative side of the invariant: a value that DOES survive
        // the write boundary must always be in the resolver's domain.
        $entity = $this->repository->create(['path' => '/note/3', 'alias' => '/reachable', 'langcode' => 'en']);
        $this->repository->save($entity, validate: false);

        $resolved = $this->resolver->resolve('/reachable');

        $this->assertNotNull($resolved, 'a successfully persisted alias must be reachable via the resolver');
        $this->assertSame('/note/3', $resolved->systemPath);
    }
}
