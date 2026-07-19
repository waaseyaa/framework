<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\Entity\EntityListTool;
use Waaseyaa\AI\Tools\Entity\EntityReadTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Mission optimistic-locking-01KTXCHY WP02/T010 — FR-008 exposure on the tool
 * read surfaces (contracts/conflict-surfaces.md §17): entity.read emits a
 * top-level revision_id, entity.list items carry the same optional member,
 * and non-revisionable types omit it (absence = "no expectation formable").
 */
#[CoversClass(EntityReadTool::class)]
#[CoversClass(EntityListTool::class)]
final class EntityToolRevisionExposureTest extends TestCase
{
    private const array REV_KEYS = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];
    private const array PLAIN_KEYS = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'];

    private DBALDatabase $db;
    private EntityRepository $revisionableRepo;
    private EntityTypeManagerInterface $revisionableEtm;
    private EntityTypeManagerInterface $plainEtm;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $resolver = new SingleConnectionResolver($this->db);
        $dispatcher = new EventDispatcher();

        $revisionableType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: self::REV_KEYS,
            revisionable: true,
            revisionDefault: true,
        );
        $handler = new SqlSchemaHandler($revisionableType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();
        $this->revisionableRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $revisionableType,
            new SqlStorageDriver($resolver),
            $dispatcher,
            new RevisionableStorageDriver($resolver, $revisionableType),
            $this->db,
        );
        $this->revisionableEtm = new SingleTypeEntityTypeManager($revisionableType, $this->revisionableRepo);

        $plainType = new EntityType(
            id: 'test_plain',
            label: 'Plain',
            class: TestStorageEntity::class,
            keys: self::PLAIN_KEYS,
        );
        new SqlSchemaHandler($plainType, $this->db)->ensureTable();
        $plainRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $plainType,
            new SqlStorageDriver($resolver),
            $dispatcher,
            null,
            $this->db,
        );
        $this->plainEtm = new SingleTypeEntityTypeManager($plainType, $plainRepo);

        $seed = new TestStorageEntity(values: ['id' => '1', 'label' => 'plain-1'], entityTypeId: 'test_plain', entityKeys: self::PLAIN_KEYS);
        $seed->enforceIsNew();
        $plainRepo->save($seed);
    }

    private function account(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 7;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, ['tool.entity.read', 'tool.entity.list'], true);
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private function seedRevisionable(string $id, string $title): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => $title, 'id' => $id, 'uuid' => 'u' . $id]);
        $entity->enforceIsNew();
        $this->revisionableRepo->save($entity);
    }

    #[Test]
    public function read_exposes_the_current_head_top_level(): void
    {
        $this->seedRevisionable('1', 'v1');

        $tool = new EntityReadTool($this->revisionableEtm);
        $result = $tool->execute(['entity_type' => 'test_revisionable', 'id' => '1'], $this->account());

        $this->assertFalse($result->isError, $result->summary ?? '');
        $data = $result->content[0]['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame(1, $data['revision_id']);

        // The head moves with each save — the read tracks it.
        $entity = $this->revisionableRepo->find('1');
        \assert($entity instanceof TestRevisionableEntity);
        $entity->set('title', 'v2');
        $this->revisionableRepo->save($entity);

        $again = $tool->execute(['entity_type' => 'test_revisionable', 'id' => '1'], $this->account());
        $this->assertSame(2, $again->content[0]['data']['revision_id'] ?? null);
    }

    #[Test]
    public function read_omits_revision_id_on_a_non_revisionable_type(): void
    {
        $tool = new EntityReadTool($this->plainEtm);
        $result = $tool->execute(['entity_type' => 'test_plain', 'id' => '1'], $this->account());

        $this->assertFalse($result->isError, $result->summary ?? '');
        $data = $result->content[0]['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('revision_id', $data, 'absence means "no expectation formable"');
    }

    #[Test]
    public function list_items_carry_the_per_item_head(): void
    {
        $this->seedRevisionable('1', 'one');
        $this->seedRevisionable('2', 'two');

        // Move entity 2's head so the per-item ids differ.
        $entity = $this->revisionableRepo->find('2');
        \assert($entity instanceof TestRevisionableEntity);
        $entity->set('title', 'two-b');
        $this->revisionableRepo->save($entity);

        $tool = new EntityListTool($this->revisionableEtm);
        $result = $tool->execute(['entity_type' => 'test_revisionable'], $this->account());

        $this->assertFalse($result->isError, $result->summary ?? '');
        $data = $result->content[0]['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame(2, $data['count']);

        $byId = [];
        foreach ($data['items'] as $item) {
            $byId[(string) $item['id']] = $item;
        }
        $this->assertSame(
            ['entity_type' => 'test_revisionable', 'id' => 1, 'label' => 'one', 'revision_id' => 1],
            $byId['1'],
        );
        $this->assertSame(
            ['entity_type' => 'test_revisionable', 'id' => 2, 'label' => 'two-b', 'revision_id' => 2],
            $byId['2'],
        );
    }

    #[Test]
    public function list_items_omit_revision_id_on_a_non_revisionable_type(): void
    {
        $tool = new EntityListTool($this->plainEtm);
        $result = $tool->execute(['entity_type' => 'test_plain'], $this->account());

        $this->assertFalse($result->isError, $result->summary ?? '');
        $data = $result->content[0]['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame(1, $data['count']);
        $this->assertSame(
            ['entity_type' => 'test_plain', 'id' => 1, 'label' => 'plain-1'],
            $data['items'][0],
            'item shape otherwise unchanged — no revision_id member',
        );
    }
}
