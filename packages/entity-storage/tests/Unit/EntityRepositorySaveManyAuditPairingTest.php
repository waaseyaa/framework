<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Audit\EntityAuditLogger;
use Waaseyaa\Entity\Audit\EntityWriteAuditListener;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Production-shaped #1856 regression: {@see EntityRepository::saveMany()}
 * dispatches every PRE_SAVE inside the batch transaction, then all buffered
 * POST_SAVE events after commit. A single-slot pendingIsNew on
 * {@see EntityWriteAuditListener} therefore attributes every row from the
 * last PRE event's isNew().
 */
#[CoversClass(EntityRepository::class)]
#[CoversClass(EntityWriteAuditListener::class)]
final class EntityRepositorySaveManyAuditPairingTest extends TestCase
{
    private string $projectRoot;
    private EntityType $entityType;
    private EventDispatcher $eventDispatcher;
    private EntityAuditLogger $logger;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_save_many_audit_pairing_' . uniqid();
        mkdir($this->projectRoot . '/storage/framework', 0755, true);
        $this->logger = new EntityAuditLogger($this->projectRoot);
        $this->eventDispatcher = new EventDispatcher();
        $this->eventDispatcher->addSubscriber(new EntityWriteAuditListener($this->logger));
        $this->entityType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    #[Test]
    public function saveManyExistingThenNewAuditsUpdateThenCreate(): void
    {
        $repository = $this->createSqlRepository();
        $existing = $this->newEntity('1', 'Existing');
        $this->assertSame(EntityConstants::SAVED_NEW, $repository->save($existing));
        file_put_contents($this->projectRoot . '/storage/framework/entity-audit.jsonl', '');

        $existing->set('label', 'Existing updated');
        $new = $this->newEntity('2', 'Brand new');
        $results = $repository->saveMany([$existing, $new]);

        $this->assertSame(
            [EntityConstants::SAVED_UPDATED, EntityConstants::SAVED_NEW],
            $results,
        );
        $this->assertSame(
            [
                ['1', 'update'],
                ['2', 'create'],
            ],
            $this->auditIdActions(),
            'saveMany([existing, new]) must pair each POST_SAVE with that entity\'s own PRE_SAVE isNew().',
        );
    }

    #[Test]
    public function saveManyNewThenExistingAuditsCreateThenUpdate(): void
    {
        $repository = $this->createSqlRepository();
        $existing = $this->newEntity('1', 'Existing');
        $this->assertSame(EntityConstants::SAVED_NEW, $repository->save($existing));
        file_put_contents($this->projectRoot . '/storage/framework/entity-audit.jsonl', '');

        $existing->set('label', 'Existing updated');
        $new = $this->newEntity('2', 'Brand new');
        $results = $repository->saveMany([$new, $existing]);

        $this->assertSame(
            [EntityConstants::SAVED_NEW, EntityConstants::SAVED_UPDATED],
            $results,
        );
        $this->assertSame(
            [
                ['2', 'create'],
                ['1', 'update'],
            ],
            $this->auditIdActions(),
            'saveMany([new, existing]) must not let the existing entity\'s PRE overwrite the new entity\'s create action.',
        );
    }

    private function createSqlRepository(): EntityRepository
    {
        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        new SqlSchemaHandler($this->entityType, $db)->ensureTable();

        return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->entityType,
            $driver,
            $this->eventDispatcher,
            database: $db,
        );
    }

    private function newEntity(string $id, string $label): TestStorageEntity
    {
        $entity = new TestStorageEntity(
            values: ['id' => $id, 'label' => $label, 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);

        return $entity;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function auditIdActions(): array
    {
        $pairs = [];
        foreach ($this->logger->read() as $entry) {
            $pairs[] = [(string) $entry['entity_id'], (string) $entry['action']];
        }

        return $pairs;
    }
}
