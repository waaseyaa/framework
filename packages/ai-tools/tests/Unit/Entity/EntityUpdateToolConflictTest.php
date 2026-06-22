<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\Entity\EntityUpdateTool;
use Waaseyaa\AI\Tools\Tests\Fixtures\InMemoryToolRepository;
use Waaseyaa\AI\Tools\Tests\Fixtures\SingleTypeEntityTypeManager;
use Waaseyaa\AI\Tools\Tests\Fixtures\ToolTestEntity;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Mission optimistic-locking-01KTXCHY WP02/T008+T009 — entity.update conflict
 * surface per contracts/conflict-surfaces.md §1–8: argument schema, threading
 * through SaveContext, the Mission 1 two-block revision_conflict payload, the
 * revision_expectation_unsupported mapping, dry-run byte-parity, success-head
 * readback, and EntityKeyGuard's unchanged refusal of revision_id in values.
 *
 * Fixture style: real sqlite repository (the WP01 unit-suite shape) — the
 * InMemoryToolRepository fixture is NOT SaveContext-capable, which is itself
 * the unsupported-path test case, not a thing to fix.
 */
#[CoversClass(EntityUpdateTool::class)]
final class EntityUpdateToolConflictTest extends TestCase
{
    private const array KEYS = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];

    private DBALDatabase $db;
    private EntityType $entityType;
    private EntityRepository $repo;
    private EntityUpdateTool $tool;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: self::KEYS,
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($this->entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $this->repo = new EntityRepository(
            $this->entityType,
            new SqlStorageDriver($resolver),
            new EventDispatcher(),
            new RevisionableStorageDriver($resolver, $this->entityType),
            $this->db,
        );

        $this->tool = new EntityUpdateTool(new SingleTypeEntityTypeManager($this->entityType, $this->repo));
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
                return $permission === 'tool.entity.update';
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

    /** Seed the fixture entity at revision 1. */
    private function seedEntity(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);
    }

    /** Move the head to revision 2 with a competing direct save. */
    private function moveHead(): void
    {
        $winner = $this->repo->find('1');
        \assert($winner instanceof TestRevisionableEntity);
        $winner->set('title', 'v2-winner');
        $this->repo->save($winner);
    }

    // -----------------------------------------------------------------------
    // Argument schema (contract §1)
    // -----------------------------------------------------------------------

    #[Test]
    public function input_schema_declares_optional_expected_revision_id(): void
    {
        $schema = $this->tool->inputSchema();

        $this->assertArrayHasKey('expected_revision_id', $schema['properties']);
        $this->assertSame('integer', $schema['properties']['expected_revision_id']['type']);
        $this->assertSame(1, $schema['properties']['expected_revision_id']['minimum']);
        $this->assertNotContains('expected_revision_id', $schema['required'], 'the expectation is optional');
    }

    #[Test]
    public function invalid_expected_revision_id_is_refused(): void
    {
        $this->seedEntity();

        foreach ([0, -3, '5', 1.5, true] as $invalid) {
            $result = $this->tool->execute(
                ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['title' => 'x'], 'expected_revision_id' => $invalid],
                $this->account(),
            );

            $this->assertTrue($result->isError, var_export($invalid, true) . ' must be refused');
            $this->assertSame(
                'entity.update: expected_revision_id must be a positive integer.',
                $result->content[0]['text'] ?? null,
            );
        }

        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('v1', $reloaded->label(), 'no refused call saved anything');
    }

    // -----------------------------------------------------------------------
    // Conflict mapping (contract §3, NFR-003)
    // -----------------------------------------------------------------------

    #[Test]
    public function stale_expectation_maps_to_the_two_block_revision_conflict_payload(): void
    {
        $this->seedEntity();
        $this->moveHead();

        $result = $this->tool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['title' => 'loser'], 'expected_revision_id' => 1],
            $this->account(),
        );

        $this->assertTrue($result->isError);
        $this->assertSame(
            "entity.update: revision conflict on test_revisionable '1': expected revision 1, current revision 2.",
            $result->content[0]['text'] ?? null,
        );
        $this->assertSame(
            [
                'error' => 'revision_conflict',
                'entity_type' => 'test_revisionable',
                'id' => '1',
                'expected' => 1,
                'current' => 2,
            ],
            $result->content[1]['data'] ?? null,
        );

        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('v2-winner', $reloaded->label(), 'the competing write is intact, the stale write absent');
        $this->assertSame(2, $reloaded->getRevisionId());
    }

    // -----------------------------------------------------------------------
    // Unsupported mapping (contract §2/§4)
    // -----------------------------------------------------------------------

    #[Test]
    public function expectation_on_a_non_revisionable_type_maps_to_unsupported(): void
    {
        $plainKeys = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'];
        $plainType = new EntityType(
            id: 'test_plain',
            label: 'Plain',
            class: TestStorageEntity::class,
            keys: $plainKeys,
        );
        new SqlSchemaHandler($plainType, $this->db)->ensureTable();
        $repo = new EntityRepository(
            $plainType,
            new SqlStorageDriver(new SingleConnectionResolver($this->db)),
            new EventDispatcher(),
            null,
            $this->db,
        );
        $seed = new TestStorageEntity(values: ['id' => '1', 'label' => 'x'], entityTypeId: 'test_plain', entityKeys: $plainKeys);
        $seed->enforceIsNew();
        $repo->save($seed);
        $tool = new EntityUpdateTool(new SingleTypeEntityTypeManager($plainType, $repo));

        $result = $tool->execute(
            ['entity_type' => 'test_plain', 'id' => '1', 'values' => ['label' => 'y'], 'expected_revision_id' => 1],
            $this->account(),
        );

        $this->assertTrue($result->isError);
        $data = $result->content[1]['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame('revision_expectation_unsupported', $data['error']);
        $this->assertSame('test_plain', $data['entity_type']);
        $this->assertStringContainsString('not revisionable', (string) $data['reason']);

        $reloaded = $repo->find('1');
        $this->assertNotNull($reloaded);
        $this->assertSame('x', $reloaded->get('label'), 'never a silent plain save');

        // Dry-run screens the same case up front (contract §6).
        $dry = $tool->dryRun(
            ['entity_type' => 'test_plain', 'id' => '1', 'values' => ['label' => 'y'], 'expected_revision_id' => 1],
            $this->account(),
        );
        $this->assertTrue($dry->isError);
        $dryData = $dry->content[1]['data'] ?? null;
        $this->assertIsArray($dryData);
        $this->assertSame('revision_expectation_unsupported', $dryData['error']);
    }

    #[Test]
    public function expectation_on_a_non_concrete_repository_maps_to_unsupported(): void
    {
        $repo = new InMemoryToolRepository();
        $repo->seed(new ToolTestEntity(['id' => '1', 'title' => 'Original']));
        $tool = new EntityUpdateTool(new SingleTypeEntityTypeManager($this->entityType, $repo));

        $result = $tool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['title' => 'x'], 'expected_revision_id' => 1],
            $this->account(),
        );

        $this->assertTrue($result->isError);
        $this->assertSame(
            [
                'error' => 'revision_expectation_unsupported',
                'entity_type' => 'test_revisionable',
                'reason' => 'repository does not support revision expectations',
            ],
            $result->content[1]['data'] ?? null,
        );
        $this->assertSame([], $repo->saved, 'a stated expectation is never silently dropped into a plain save');
    }

    // -----------------------------------------------------------------------
    // Success (contract §7) + no-expectation path (§8)
    // -----------------------------------------------------------------------

    #[Test]
    public function matching_expectation_applies_and_returns_the_new_head(): void
    {
        $this->seedEntity();

        $result = $this->tool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['title' => 'v2'], 'expected_revision_id' => 1],
            $this->account(),
        );

        $this->assertFalse($result->isError, $result->summary ?? '');
        $this->assertSame(
            [
                'entity_type' => 'test_revisionable',
                'id' => '1',
                'result' => EntityConstants::SAVED_UPDATED,
                'revision_id' => 2,
            ],
            $result->content[0]['data'] ?? null,
        );

        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('v2', $reloaded->label());
        $this->assertSame(2, $reloaded->getRevisionId());
    }

    #[Test]
    public function no_expectation_call_keeps_legacy_last_write_wins(): void
    {
        $this->seedEntity();
        $this->moveHead();

        $result = $this->tool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['title' => 'v3-no-expectation']],
            $this->account(),
        );

        $this->assertFalse($result->isError);
        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('v3-no-expectation', $reloaded->label());
        $this->assertSame(3, $reloaded->getRevisionId());
    }

    #[Test]
    public function revision_id_inside_values_is_still_refused_even_with_an_expectation(): void
    {
        $this->seedEntity();

        $result = $this->tool->execute(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['revision_id' => 2, 'title' => 'x'], 'expected_revision_id' => 1],
            $this->account(),
        );

        $this->assertTrue($result->isError);
        $this->assertSame(
            'entity.update: refused identity keys: revision_id — identity fields cannot be written through this tool',
            $result->content[0]['text'] ?? null,
        );
        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('v1', $reloaded->label(), 'whole-write rejection');
    }

    // -----------------------------------------------------------------------
    // Dry-run parity (contract §6, T009)
    // -----------------------------------------------------------------------

    #[Test]
    public function dry_run_conflict_payload_is_byte_identical_to_execute(): void
    {
        $this->seedEntity();
        $this->moveHead();

        $arguments = ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['title' => 'loser'], 'expected_revision_id' => 1];

        $dry = $this->tool->dryRun($arguments, $this->account());
        $real = $this->tool->execute($arguments, $this->account());

        $this->assertTrue($dry->isError);
        $this->assertTrue($real->isError);
        $this->assertSame(
            json_encode($real->content, JSON_THROW_ON_ERROR),
            json_encode($dry->content, JSON_THROW_ON_ERROR),
            'dry-run and execute conflict payloads must not fork (single builder)',
        );
        $this->assertSame($real->summary, $dry->summary);
    }

    #[Test]
    public function dry_run_with_matching_expectation_still_reports_would_update(): void
    {
        $this->seedEntity();

        $arguments = ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['title' => 'v2'], 'expected_revision_id' => 1];
        $result = $this->tool->dryRun($arguments, $this->account());

        $this->assertFalse($result->isError);
        $this->assertSame(['would_update' => $arguments], $result->content[0]['data'] ?? null);

        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame(1, $reloaded->getRevisionId(), 'a dry run never writes');
    }

    #[Test]
    public function dry_run_without_the_argument_is_unchanged(): void
    {
        $this->seedEntity();
        $this->moveHead();

        $arguments = ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['title' => 'x']];
        $result = $this->tool->dryRun($arguments, $this->account());

        $this->assertFalse($result->isError);
        $this->assertSame(['would_update' => $arguments], $result->content[0]['data'] ?? null);
    }

    #[Test]
    public function dry_run_with_invalid_expectation_is_refused(): void
    {
        $this->seedEntity();

        $result = $this->tool->dryRun(
            ['entity_type' => 'test_revisionable', 'id' => '1', 'values' => ['title' => 'x'], 'expected_revision_id' => 0],
            $this->account(),
        );

        $this->assertTrue($result->isError);
        $this->assertSame(
            'entity.update: expected_revision_id must be a positive integer.',
            $result->content[0]['text'] ?? null,
        );
    }

    #[Test]
    public function dry_run_on_a_missing_entity_reports_conflict_with_null_current(): void
    {
        // Nothing seeded: the row does not exist, so no expectation can match.
        $result = $this->tool->dryRun(
            ['entity_type' => 'test_revisionable', 'id' => '404', 'values' => ['title' => 'x'], 'expected_revision_id' => 1],
            $this->account(),
        );

        $this->assertTrue($result->isError);
        $data = $result->content[1]['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame('revision_conflict', $data['error']);
        $this->assertArrayHasKey('current', $data);
        $this->assertNull($data['current'], 'a vanished row serializes current as null, not dropped');
    }
}
