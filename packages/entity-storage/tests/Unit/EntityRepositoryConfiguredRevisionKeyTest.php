<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\EntityMutationConflictException;
use Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\Field\FieldDefinition;

#[CoversClass(EntityRepository::class)]
final class EntityRepositoryConfiguredRevisionKeyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function revisionKeys(): iterable
    {
        yield 'default revision_id' => ['revision_id'];
        yield 'custom vid' => ['vid'];
    }

    #[Test]
    #[DataProvider('revisionKeys')]
    public function configured_base_pointer_is_used_across_revision_operations(string $revisionKey): void
    {
        [$database, $repository, $table] = $this->repository($revisionKey);

        $entity = $repository->create([
            'id' => '1',
            'uuid' => 'one',
            'title' => 'v1',
            'status' => false,
            'published_revision_id' => null,
        ]);
        $repository->save($entity);

        $entity = $repository->find('1');
        self::assertNotNull($entity);
        $entity->set('title', 'v2');
        $repository->save($entity);

        self::assertSame(2, (int) $this->baseRow($database, $table)[$revisionKey]);
        self::assertSame(2, $repository->find('1')?->getRevisionId());
        self::assertSame(1, $repository->loadRevision('1', 1)?->getRevisionId());
        self::assertSame('v1', $repository->loadRevision('1', 1)?->label());
        self::assertSame('v2', $repository->loadWorkingCopy('1')?->label());

        $current = $repository->setCurrentRevision('1', 1, $this->mutationToken($repository));
        self::assertSame('v1', $current->label());
        self::assertSame(1, (int) $this->baseRow($database, $table)[$revisionKey]);

        $rolledBack = $repository->rollback('1', 2, $this->mutationToken($repository));
        self::assertSame('v2', $rolledBack->label());
        self::assertSame(3, $rolledBack->getRevisionId());
        self::assertSame(3, (int) $this->baseRow($database, $table)[$revisionKey]);

        $published = $repository->promotePublishedRevision('1', 2, $this->mutationToken($repository));
        self::assertSame('v2', $published->label());
        $baseRow = $this->baseRow($database, $table);
        self::assertSame(2, (int) $baseRow[$revisionKey]);
        self::assertSame(2, (int) $baseRow['published_revision_id']);

        $report = $repository->pruneRevisions(
            '1',
            RevisionPruningPolicy::keepLastUniform(1),
            $this->mutationToken($repository),
        );
        self::assertSame(1, $report->pruned);
        self::assertSame([3, 2], array_map(
            static fn(TestRevisionableEntity $revision): int => (int) $revision->getRevisionId(),
            $repository->listRevisions('1'),
        ));

        $columns = $database->getConnection()->createSchemaManager()->listTableColumns($table);
        self::assertArrayHasKey($revisionKey, $columns);
        if ($revisionKey === 'vid') {
            self::assertArrayNotHasKey('revision_id', $columns);
        }

        $revisionColumns = $database->getConnection()->createSchemaManager()->listTableColumns($table . '_revision');
        self::assertArrayHasKey('revision_id', $revisionColumns);
        self::assertArrayNotHasKey('vid', $revisionColumns);
    }

    #[Test]
    #[DataProvider('revisionKeys')]
    public function backfill_writes_the_configured_base_pointer(string $revisionKey): void
    {
        [$database, $repository, $table] = $this->repository($revisionKey, 'backfill');
        $database->getConnection()->insert($table, [
            'id' => 7,
            'uuid' => 'legacy',
            'title' => 'legacy',
            'bundle' => $table,
            'langcode' => 'en',
            'status' => 0,
            'published_revision_id' => null,
        ]);

        self::assertSame(1, $repository->backfillMutationAuthorities('custom revision key test'));
        self::assertSame(1, $repository->backfillInitialRevisions());
        self::assertSame(1, (int) $this->baseRow($database, $table, 7)[$revisionKey]);
        self::assertSame(1, $repository->loadRevision('7', 1)?->getRevisionId());
        self::assertSame(0, $repository->backfillInitialRevisions());
    }

    #[Test]
    #[DataProvider('revisionKeys')]
    public function stale_pointer_move_is_rejected_without_changing_the_configured_pointer(string $revisionKey): void
    {
        [$database, $repository, $table] = $this->repository($revisionKey, 'stale');
        $entity = $repository->create([
            'id' => '1',
            'uuid' => 'one',
            'title' => 'v1',
            'status' => false,
            'published_revision_id' => null,
        ]);
        $repository->save($entity);

        $stale = $repository->find('1');
        $winner = $repository->find('1');
        self::assertNotNull($stale?->mutationToken());
        self::assertNotNull($winner);
        $winner->set('title', 'v2');
        $repository->save($winner);

        try {
            $repository->setCurrentRevision('1', 1, $stale->mutationToken());
            self::fail('A stale mutation token moved the revision pointer.');
        } catch (EntityMutationConflictException) {
            self::assertSame(2, (int) $this->baseRow($database, $table)[$revisionKey]);
            self::assertSame('v2', $repository->find('1')?->label());
            self::assertCount(2, $repository->listRevisions('1'));
        }
    }

    /** @return array{DBALDatabase, EntityRepository, string} */
    private function repository(string $revisionKey, string $suffix = 'operations'): array
    {
        $database = DBALDatabase::createSqlite();
        $table = 'configured_revision_' . $suffix . '_' . $revisionKey;
        $type = new EntityType(
            id: $table,
            label: 'Configured revision key',
            class: TestRevisionableEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'revision' => $revisionKey,
            ],
            revisionable: true,
            revisionDefault: true,
            primaryStorageBackend: 'sql-column',
        );
        $fields = [
            new FieldDefinition(name: 'status', type: 'boolean'),
            new FieldDefinition(name: 'published_revision_id', type: 'integer'),
        ];
        $schema = new SqlSchemaHandler(
            $type,
            $database,
            primaryBackendId: 'sql-column',
            entityLevelFields: $fields,
        );
        $schema->ensureTable();
        $schema->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($database);
        $repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            new SqlStorageDriver($resolver),
            new EventDispatcher(),
            new RevisionableStorageDriver($resolver, $type),
            $database,
        );

        return [$database, $repository, $table];
    }

    /** @return array<string, mixed> */
    private function baseRow(DBALDatabase $database, string $table, int $id = 1): array
    {
        $row = $database->getConnection()->fetchAssociative(
            'SELECT * FROM ' . $table . ' WHERE id = ?',
            [$id],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function mutationToken(EntityRepository $repository): \Waaseyaa\Entity\Concurrency\EntityMutationToken
    {
        $token = $repository->find('1')?->mutationToken();
        self::assertNotNull($token);

        return $token;
    }
}
