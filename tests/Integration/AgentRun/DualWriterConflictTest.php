<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AgentRun;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\Entity\EntityReadTool;
use Waaseyaa\AI\Tools\Entity\EntityUpdateTool;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

/**
 * Mission optimistic-locking-01KTXCHY WP02/T012 — the SC-001 dual-writer
 * story end-to-end through the agent tool surface against a real
 * repository-backed entity-type manager (contracts/conflict-surfaces.md §19):
 *
 *   writer A reads head R → writer B updates (head → R+1) → writer A's
 *   expectation-stated update surfaces a structured revision_conflict (B's
 *   write intact, A's absent) → writer A re-reads, restates, succeeds — the
 *   final entity carries both writers' fields (disjoint-merge success path).
 *
 * The same interleave WITHOUT an expectation documents today's last-write-wins
 * (the "before" of the mission).
 */
#[CoversNothing]
final class DualWriterConflictTest extends TestCase
{
    private const array KEYS = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];

    private DBALDatabase $db;
    private EntityTypeManager $entityTypeManager;
    private EntityRepository $repo;
    private EntityReadTool $readTool;
    private EntityUpdateTool $updateTool;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->db);

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            null,
            // The kernel's getRepository() shape: revision-aware pipeline over SQLite.
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $resolver): EntityRepository {
                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver($resolver),
                    $dispatcher,
                    $definition->isRevisionable() ? new RevisionableStorageDriver($resolver, $definition) : null,
                    $this->db,
                );
            },
        );

        $entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: self::KEYS,
            revisionable: true,
            revisionDefault: true,
        );
        $this->entityTypeManager->registerEntityType($entityType);
        $handler = new SqlSchemaHandler($entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $repo = $this->entityTypeManager->getRepository('test_revisionable');
        \assert($repo instanceof EntityRepository);
        $this->repo = $repo;

        $this->readTool = new EntityReadTool($this->entityTypeManager);
        $this->updateTool = new EntityUpdateTool($this->entityTypeManager);
    }

    private function account(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 42;
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return ['administrator'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private function seed(string $id, string $title): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => $title, 'id' => $id, 'uuid' => 'u' . $id]);
        $entity->enforceIsNew();
        $this->repo->save($entity);
    }

    #[Test]
    public function dualWriterConflictIsSurfacedAndMachineCorrectable(): void
    {
        $this->seed('1', 'v1');
        $account = $this->account();

        // Writer A reads the head via the read surface and notes R.
        $read = $this->readTool->execute(['entity_type' => 'test_revisionable', 'id' => '1'], $account);
        self::assertFalse($read->isError, $read->summary ?? '');
        $headR = $read->content[0]['data']['revision_id'] ?? null;
        $tokenR = $read->content[0]['data']['mutation_token'] ?? null;
        self::assertSame(1, $headR, 'writer A forms its expectation from the read surface');
        self::assertIsString($tokenR);

        // Writer B lands first (no expectation) — head moves to R+1.
        $writeB = $this->updateTool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['summary' => 'B summary'], 'mutation_token' => $tokenR],
            $account,
        );
        self::assertFalse($writeB->isError, $writeB->summary ?? '');
        self::assertSame(2, $writeB->content[0]['data']['revision_id'] ?? null);

        // Writer A states the now-stale expectation R — the conflict surfaces
        // as the structured error, never a silent revert of B's write.
        $writeA = $this->updateTool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['title' => 'A title'], 'mutation_token' => $tokenR],
            $account,
        );
        self::assertTrue($writeA->isError, 'the stale write must be refused');
        self::assertSame(
            [
                'error' => 'mutation_conflict',
                'entity_type' => 'test_revisionable',
                'id' => '1',
            ],
            $writeA->content[1]['data'] ?? null,
        );

        // B's field value is intact; A's attempted value is absent.
        $afterConflict = $this->repo->find('1');
        self::assertNotNull($afterConflict);
        self::assertSame('B summary', $afterConflict->get('summary'));
        self::assertSame('v1', $afterConflict->get('title'), "A's write must not have landed");

        // Recovery loop: writer A re-reads the moved head and restates.
        $reread = $this->readTool->execute(['entity_type' => 'test_revisionable', 'id' => '1'], $account);
        self::assertFalse($reread->isError);
        $newHead = $reread->content[0]['data']['revision_id'] ?? null;
        $newToken = $reread->content[0]['data']['mutation_token'] ?? null;
        self::assertSame(2, $newHead);
        self::assertIsString($newToken);

        $retryA = $this->updateTool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['title' => 'A title'], 'mutation_token' => $newToken],
            $account,
        );
        self::assertFalse($retryA->isError, $retryA->summary ?? '');
        self::assertSame(3, $retryA->content[0]['data']['revision_id'] ?? null);

        // Disjoint-merge success: the final entity carries BOTH writers' fields.
        $final = $this->repo->find('1');
        self::assertNotNull($final);
        self::assertSame('A title', $final->get('title'));
        self::assertSame('B summary', $final->get('summary'));
    }

    #[Test]
    public function omissionCannotDegradeTheSameInterleaveToLastWriteWins(): void
    {
        $this->seed('2', 'original');
        $account = $this->account();

        // Writer A reads head 1 (and then ignores it — no expectation stated).
        $read = $this->readTool->execute(['entity_type' => 'test_revisionable', 'id' => '2'], $account);
        self::assertSame(1, $read->content[0]['data']['revision_id'] ?? null);
        $token = $read->content[0]['data']['mutation_token'] ?? null;
        self::assertIsString($token);

        // Writer B updates the same field first.
        $writeB = $this->updateTool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '2', 'values' => ['title' => 'B title'], 'mutation_token' => $token],
            $account,
        );
        self::assertFalse($writeB->isError);

        // Writer A omits the token. The boundary refuses the blind write.
        $writeA = $this->updateTool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '2', 'values' => ['title' => 'A title']],
            $account,
        );
        self::assertTrue($writeA->isError, 'an omitted token must never become a blind write');
        self::assertSame('entity.update: mutation_token is required.', $writeA->summary);

        $final = $this->repo->find('2');
        self::assertNotNull($final);
        self::assertSame('B title', $final->get('title'), 'the winning write remains intact');
    }
}
