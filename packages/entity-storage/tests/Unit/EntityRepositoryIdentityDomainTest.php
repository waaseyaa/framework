<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Regression cover for #2674: the repository accepts the identity domain the
 * framework's own producers emit, and never re-interprets a stored key
 * numerically on the way in.
 *
 * The `database:` argument to the factory is load-bearing, not incidental: it
 * wires a real EntityMutationAuthority, and a null-authority composition hides
 * the string-keyed failure behind a silently wrong hydration.
 */
#[CoversClass(EntityRepository::class)]
final class EntityRepositoryIdentityDomainTest extends TestCase
{
    /** An auto-increment ("content") type: SQLite hands back integer ids. */
    private function integerKeyedRepository(): EntityRepository
    {
        $db = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
        (new SqlSchemaHandler($entityType, $db))->ensureTable();

        return V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver(new SingleConnectionResolver($db)),
            new EventDispatcher(),
            database: $db,
        );
    }

    /**
     * A machine-name ("config") type: no uuid key, so SqlSchemaHandler emits a
     * varchar id column and the caller supplies the key. This is the shape
     * node_type and taxonomy_vocabulary use.
     */
    private function stringKeyedRepository(): EntityRepository
    {
        $db = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'vocabulary',
            label: 'Vocabulary',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
        );
        (new SqlSchemaHandler($entityType, $db))->ensureTable();

        return V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver(new SingleConnectionResolver($db)),
            new EventDispatcher(),
            database: $db,
        );
    }

    private function saveWithId(EntityRepository $repository, string $id, string $title): void
    {
        $entity = $repository->create(['id' => $id, 'title' => $title]);
        $entity->enforceIsNew();
        $repository->save($entity, validate: false);
    }

    #[Test]
    public function integerKeyedQueryResultPassesDirectlyToFind(): void
    {
        $repository = $this->integerKeyedRepository();
        $repository->save($repository->create(['title' => 'Hello']), validate: false);

        $ids = $repository->getQuery()->accessCheck(false)->execute();
        $first = reset($ids);

        // Assert the int actually occurs, so the test cannot pass vacuously.
        self::assertIsInt($first, 'SQLite returns integer ids for an integer identity column.');
        self::assertNotNull($repository->find($first), 'A query-produced id must load without a cast.');
    }

    #[Test]
    public function hydratedIdentityRoundTripsThroughTheSingularReadApi(): void
    {
        $repository = $this->integerKeyedRepository();
        $repository->save($repository->create(['title' => 'Hello']), validate: false);

        $entity = $repository->findBy([])[0] ?? null;
        self::assertNotNull($entity);
        $id = $entity->id();
        self::assertIsInt($id, 'Hydration reports a numeric identity as an int.');

        self::assertNotNull($repository->find($id));
        self::assertTrue($repository->exists($id));
        self::assertNotNull($repository->loadWorkingCopy($id));
    }

    #[Test]
    public function stringKeyedNumericLookingIdsAreNotCoerced(): void
    {
        $repository = $this->stringKeyedRepository();
        foreach (['article', '007', '1e3', '2024', 'tags', '0x1A'] as $machineName) {
            $this->saveWithId($repository, $machineName, 'T-' . $machineName);
        }

        $ids = $repository->getQuery()->accessCheck(false)->execute();

        // '2024' round-trips through (int) exactly, so it is reported as an int.
        // The rest do not, and must come back as the strings actually stored.
        self::assertContains(2024, $ids, 'A canonical numeric key is reported as an int.');
        foreach (['article', '007', '1e3', 'tags', '0x1A'] as $machineName) {
            self::assertContains(
                $machineName,
                $ids,
                \sprintf('Machine name %s must not be re-interpreted numerically.', $machineName),
            );
        }

        foreach (['article', '007', '1e3', '2024', 'tags', '0x1A'] as $machineName) {
            $entity = $repository->find($machineName);
            self::assertNotNull($entity, \sprintf('find(%s) must load its own row.', $machineName));
            self::assertSame(
                $machineName,
                (string) $entity->id(),
                \sprintf('%s must address and report its own key.', $machineName),
            );
        }
    }

    #[Test]
    public function findManyAndFindShareOneIdentityRule(): void
    {
        $repository = $this->integerKeyedRepository();
        $repository->save($repository->create(['title' => 'A']), validate: false);
        $repository->save($repository->create(['title' => 'B']), validate: false);

        $ids = $repository->getQuery()->accessCheck(false)->execute();

        $viaFindMany = array_map(
            static fn($entity) => $entity->id(),
            $repository->findMany($ids),
        );
        $viaFind = [];
        foreach ($ids as $id) {
            $entity = $repository->find($id);
            self::assertNotNull($entity);
            $viaFind[] = $entity->id();
        }

        self::assertSame($viaFindMany, $viaFind, 'Both read paths must resolve identity identically.');

        // The empty identity answer is unchanged by the widening.
        self::assertNull($repository->find(''));
        self::assertSame([], $repository->findMany(['']));
    }

    /**
     * The coercion rule itself, pinned against measured PHP behaviour: a stored
     * value is reported as an int only when the cast round-trips exactly.
     */
    #[Test]
    public function identityCoercionRoundTripsOrStaysString(): void
    {
        $becomesInt = ['1', '1000', '0', '-1', '2024'];
        $staysString = ['007', '1e3', ' 9', '+5', '1.5', '0x1A', '9223372036854775808'];

        foreach ($becomesInt as $value) {
            self::assertSame($value, (string) (int) $value, \sprintf('%s should round-trip.', $value));
        }
        foreach ($staysString as $value) {
            self::assertNotSame($value, (string) (int) $value, \sprintf('%s should not round-trip.', $value));
        }
    }
}
