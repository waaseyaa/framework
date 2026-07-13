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
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\AbortOperationException;
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
use Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

/**
 * CW-v1 option-1 forward-draft rebuild — storage mechanics (#1920 PR-1).
 *
 * Discipline is a workflow-layer decision (the forthcoming
 * `WorkflowStateGuard` / `WorkflowPointerMoveGuard`, wired in the next PR);
 * storage only supplies the mechanics and ships dormant here. These tests set
 * the transient entity flag ({@see \Waaseyaa\Entity\RevisionableEntityTrait::setDefaultRevisionDiscipline()})
 * and the event flag ({@see BeforeRevisionPointerMoveEvent::applyDefaultRevisionSemantics()})
 * directly, standing in for the guard.
 *
 * @see \Waaseyaa\EntityStorage\Tests\Unit\EntityRepositoryPublishedRevisionTest
 *      for the unflagged `setPublishedRevision()` pin that MUST stay green,
 *      byte-identical, untouched by this PR.
 */
#[CoversClass(EntityRepository::class)]
final class DefaultRevisionDisciplineTest extends TestCase
{
    private DBALDatabase $db;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->dispatcher = new EventDispatcher();
    }

    private function buildRepo(): EntityRepository
    {
        $entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver);
        $revisionDriver = new RevisionableStorageDriver($resolver, $entityType);

        return new EntityRepository(
            $entityType,
            $driver,
            $this->dispatcher,
            $revisionDriver,
            $this->db,
        );
    }

    /** @return array<string, mixed> */
    private function rawBaseRow(string $id): array
    {
        $result = $this->db->query('SELECT * FROM test_revisionable WHERE id = ?', [$id]);
        foreach ($result as $row) {
            return (array) $row;
        }

        return [];
    }

    /**
     * The base-row columns whose values differ between two raw-row snapshots
     * (sorted) — the full-row oracle for the byte-identity regression gate:
     * asserting this list pins that NOTHING ELSE changed.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<string>
     */
    private function changedColumns(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $column => $value) {
            if (!\array_key_exists($column, $before) || $before[$column] !== $value) {
                $changed[] = (string) $column;
            }
        }
        foreach (\array_keys($before) as $column) {
            if (!\array_key_exists($column, $after)) {
                $changed[] = (string) $column;
            }
        }
        \sort($changed);

        return $changed;
    }

    /** Registers a listener that always applies default-revision semantics to pointer moves. */
    private function alwaysApplyDefaultRevisionSemantics(): void
    {
        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            static function (BeforeRevisionPointerMoveEvent $event): void {
                $event->applyDefaultRevisionSemantics();
            },
        );
    }

    // ------------------------------------------------------------------
    // 1. Disciplined revision-creating save
    // ------------------------------------------------------------------

    #[Test]
    public function disciplined_revision_creating_save_leaves_base_row_byte_identical(): void
    {
        $repo = $this->buildRepo();

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);
        $repo->setPublishedRevision('1', 1);

        $before = $this->rawBaseRow('1');

        $entity = $repo->find('1');
        $entity->setDefaultRevisionDiscipline(true);
        $entity->set('title', 'v2');
        $repo->save($entity);

        $after = $this->rawBaseRow('1');
        $this->assertSame($before, $after, 'the base row must be byte-identical before/after a disciplined revision-creating save');

        $this->assertSame('v2', $repo->loadRevision('1', 2)?->label(), 'the new tip revision was written');
        $this->assertSame('v1', $repo->find('1')?->label(), 'find() still serves the published/base content');
        $this->assertSame('v2', $repo->loadWorkingCopy('1')?->label(), 'loadWorkingCopy() serves the tip');
        $this->assertSame(2, $entity->getRevisionId(), 'the in-memory entity carries its new tip revision id');
    }

    // ------------------------------------------------------------------
    // 2. Disciplined in-place save of the published-pointer revision
    // ------------------------------------------------------------------

    #[Test]
    public function disciplined_in_place_save_of_published_revision_updates_base_row(): void
    {
        $repo = $this->buildRepo();

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);
        $repo->setPublishedRevision('1', 1);

        $entity = $repo->find('1');
        $this->assertSame(1, $entity->getRevisionId(), 'sanity: the tip IS the published revision');
        $entity->setDefaultRevisionDiscipline(true);
        $entity->set('title', 'v1-edited');
        $entity->setNewRevision(false);
        $repo->save($entity);

        $this->assertSame('v1-edited', $repo->find('1')?->label(), 'in-place edit of the published revision reaches the base row');
        $this->assertSame('v1-edited', $repo->loadRevision('1', 1)?->label(), 'the revision row was updated in place too');
        $this->assertSame(1, $this->rawBaseRow('1')['revision_id'] ?? null, 'base pointer stays put (in-place, no new revision)');
    }

    // ------------------------------------------------------------------
    // 3. Disciplined in-place save of a diverged (non-published) tip
    // ------------------------------------------------------------------

    #[Test]
    public function disciplined_in_place_save_of_diverged_tip_stays_revision_only(): void
    {
        $repo = $this->buildRepo();

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);
        $repo->setPublishedRevision('1', 1);

        // Diverge: a disciplined revision-creating save produces tip rev 2,
        // base row stays on rev 1 (test 1's mechanic).
        $entity = $repo->find('1');
        $entity->setDefaultRevisionDiscipline(true);
        $entity->set('title', 'v2');
        $repo->save($entity);

        $before = $this->rawBaseRow('1');

        // Now edit the diverged tip (rev 2) in place.
        $entity = $repo->loadRevision('1', 2);
        $entity->setDefaultRevisionDiscipline(true);
        $entity->set('title', 'v2-edited');
        $entity->setNewRevision(false);
        $repo->save($entity);

        $after = $this->rawBaseRow('1');
        $this->assertSame($before, $after, 'an in-place edit of a diverged (non-published) tip must not touch the base row');
        $this->assertSame('v2-edited', $repo->loadRevision('1', 2)?->label(), 'the revision row itself was updated in place');
        $this->assertSame('v1', $repo->find('1')?->label(), 'the published/base content is unaffected');
        $this->assertSame('v2-edited', $repo->loadWorkingCopy('1')?->label());
    }

    // ------------------------------------------------------------------
    // 4. Undisciplined-pointered byte-identity (Playbook-H shape)
    // ------------------------------------------------------------------

    #[Test]
    public function undisciplined_entity_with_published_pointer_saves_byte_identically_to_today(): void
    {
        $repo = $this->buildRepo();

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);
        // Pointered, exactly like a Playbook-H install steps 1-4 (bound = NO):
        // the discipline flag is never set on any save/event below.
        $repo->setPublishedRevision('1', 1);

        // Ordinary save (no discipline flag): the base row advances exactly
        // as it always has, even though a published pointer is set. Full-row
        // oracle: the ONLY columns that change are the one the save itself
        // carries (title, a real column on this fixture) plus the advancing
        // revision_id — any other delta is a discipline leak.
        $beforeOrdinarySave = $this->rawBaseRow('1');
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity);

        $afterOrdinarySave = $this->rawBaseRow('1');
        $this->assertSame('v2', $repo->find('1')?->label(), 'ordinary save still advances the base row');
        $this->assertSame(
            ['revision_id', 'title'],
            $this->changedColumns($beforeOrdinarySave, $afterOrdinarySave),
            'an undisciplined pointered save must change exactly the saved content column and the tip pointer — nothing else',
        );
        $this->assertSame(2, $afterOrdinarySave['revision_id'] ?? null, 'base revision_id pointer still tracks the tip');

        // setPublishedRevision() stays the targeted single-column update:
        // the FULL base row is untouched except the pointer column itself.
        $beforeRepublish = $this->rawBaseRow('1');
        $repo->setPublishedRevision('1', 1);
        $afterRepublish = $this->rawBaseRow('1');
        $this->assertSame(
            [],
            $this->changedColumns($beforeRepublish, $afterRepublish),
            'undisciplined setPublishedRevision() must not change any column but the pointer (already 1 here)',
        );
        $this->assertSame('v2', $repo->find('1')?->label(), 'setPublishedRevision() never rewrites base content when undisciplined');
        $this->assertSame(2, $afterRepublish['revision_id'] ?? null, 'base revision_id pointer is untouched by setPublishedRevision()');
        $this->assertSame(1, $afterRepublish['published_revision_id'] ?? null);

        // rollback() stays unchanged: it DOES write the base row (a new
        // revision is created AND repointed).
        $repo->rollback('1', 1);
        $this->assertSame('v1', $repo->find('1')?->label(), 'rollback() still writes the base row when undisciplined');
        $this->assertSame(3, $this->rawBaseRow('1')['revision_id'] ?? null, 'rollback() created and pointed at a new revision');
    }

    // ------------------------------------------------------------------
    // 5. Flagged setPublishedRevision()
    // ------------------------------------------------------------------

    #[Test]
    public function flagged_set_published_revision_writes_target_values_and_moves_both_pointers(): void
    {
        $repo = $this->buildRepo();

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity); // rev 2, base tracks it (ordinary, undisciplined save)

        $this->alwaysApplyDefaultRevisionSemantics();
        $repo->setPublishedRevision('1', 1);

        $row = $this->rawBaseRow('1');
        $this->assertSame(1, $row['revision_id'] ?? null, 'base revision_id pointer now equals the target');
        $this->assertSame(1, $row['published_revision_id'] ?? null, 'published pointer now equals the target');
        $this->assertSame('v1', $repo->find('1')?->label(), 'base row content was copied from the target revision');
        $this->assertSame('v1', $repo->loadPublishedRevision('1')?->label());
    }

    #[Test]
    public function flagged_set_published_revision_abort_leaves_everything_untouched(): void
    {
        $repo = $this->buildRepo();

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity); // rev 2, base tracks it

        $before = $this->rawBaseRow('1');

        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            static function (BeforeRevisionPointerMoveEvent $event): void {
                $event->applyDefaultRevisionSemantics();
                throw new AbortOperationException('publish refused');
            },
        );

        try {
            $repo->setPublishedRevision('1', 1);
            $this->fail('AbortOperationException should have propagated out of setPublishedRevision().');
        } catch (AbortOperationException $e) {
            $this->assertSame('publish refused', $e->reason);
        }

        $this->assertSame($before, $this->rawBaseRow('1'), 'a denied flagged publish must leave the base row completely untouched');
        $this->assertNull($repo->loadPublishedRevision('1'), 'the published pointer never moved');
    }

    // ------------------------------------------------------------------
    // 6. Flagged rollback()
    // ------------------------------------------------------------------

    #[Test]
    public function flagged_rollback_is_revision_only(): void
    {
        $repo = $this->buildRepo();

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity); // rev 1
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity); // rev 2, base tracks it

        $before = $this->rawBaseRow('1');

        $this->alwaysApplyDefaultRevisionSemantics();
        $rolledBack = $repo->rollback('1', 1);

        $this->assertSame($before, $this->rawBaseRow('1'), 'a flagged rollback must not touch the base row at all');
        $this->assertSame('v2', $repo->find('1')?->label(), 'the base row keeps serving what it served before the rollback');
        $this->assertSame('v1', $rolledBack->label(), 'the new revision carries the restored content');
        $this->assertCount(3, $repo->listRevisions('1'), 'the rollback created a brand-new revision, not a repoint');
        $this->assertSame('v1', $repo->loadWorkingCopy('1')?->label(), 'the working copy is the new (rolled-back) tip');
    }

    // ------------------------------------------------------------------
    // 7. Prune/delete: latest revision immortal
    // ------------------------------------------------------------------

    #[Test]
    public function latest_revision_is_immortal_alongside_current_and_published(): void
    {
        $repo = $this->buildRepo();

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity); // rev 1 (current + published pointer will sit here)
        $repo->setPublishedRevision('1', 1);

        // Two disciplined revision-creating saves diverge the tip further and
        // further while the base row (current + published) stays on rev 1.
        $entity = $repo->find('1');
        $entity->setDefaultRevisionDiscipline(true);
        $entity->set('title', 'v2');
        $repo->save($entity); // rev 2

        $entity = $repo->loadRevision('1', 2);
        $entity->setDefaultRevisionDiscipline(true);
        $entity->set('title', 'v3');
        $repo->save($entity); // rev 3 (latest)

        // Direct driver guard: deleting the latest revision throws, same as
        // the existing current/published guards.
        $storageDriver = new RevisionableStorageDriver(
            new SingleConnectionResolver($this->db),
            new EntityType(
                id: 'test_revisionable',
                label: 'Test',
                class: TestRevisionableEntity::class,
                keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
                revisionable: true,
                revisionDefault: true,
            ),
        );
        $this->expectExceptionMessageMatches('/latest revision/');
        $this->expectException(\LogicException::class);
        $storageDriver->deleteRevision('1', 3);
    }

    #[Test]
    public function pruning_never_deletes_the_latest_revision(): void
    {
        $repo = $this->buildRepo();

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity); // rev 1 (current)

        $entity = $repo->find('1');
        $entity->setDefaultRevisionDiscipline(true);
        $entity->set('title', 'v2');
        $repo->save($entity); // rev 2 (diverged, prunable in principle)

        $entity = $repo->loadRevision('1', 2);
        $entity->setDefaultRevisionDiscipline(true);
        $entity->set('title', 'v3');
        $repo->save($entity); // rev 3 (latest — immortal)

        $report = $repo->pruneRevisions('1', RevisionPruningPolicy::keepLastUniform(0));

        $this->assertSame(3, $report->candidatesFound);
        $this->assertSame(1, $report->pruned, 'only rev 2 (neither current nor latest) is prunable');
        $this->assertSame(2, $report->retained);
        $this->assertNotNull($repo->loadRevision('1', 1), 'current revision survives (pre-existing guard)');
        $this->assertNotNull($repo->loadRevision('1', 3), 'latest revision survives (this PR\'s new guard)');
        $this->assertNull($repo->loadRevision('1', 2), 'the diverged, non-latest revision was pruned');
    }

    // ------------------------------------------------------------------
    // 8. Disciplined save + withExpectedRevisionId -> LogicException
    // ------------------------------------------------------------------

    #[Test]
    public function disciplined_save_with_expected_revision_id_throws(): void
    {
        $repo = $this->buildRepo();

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);

        $before = $this->rawBaseRow('1');

        $entity = $repo->find('1');
        $entity->setDefaultRevisionDiscipline(true);
        $entity->set('title', 'v2');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/default-revision-disciplined/');
        try {
            $repo->save($entity, true, SaveContext::default()->withExpectedRevisionId(1));
        } finally {
            $this->assertSame($before, $this->rawBaseRow('1'), 'the rejection happens before any write');
            $this->assertCount(1, $repo->listRevisions('1'), 'no revision was written either');
        }
    }

    // ------------------------------------------------------------------
    // 9. loadWorkingCopy() === find() with no divergence / no revision driver
    // ------------------------------------------------------------------

    #[Test]
    public function load_working_copy_matches_find_when_there_is_no_divergence(): void
    {
        $repo = $this->buildRepo();

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);

        $this->assertSame($repo->find('1')?->label(), $repo->loadWorkingCopy('1')?->label());
        $this->assertSame($repo->find('1')?->getRevisionId(), $repo->loadWorkingCopy('1')?->getRevisionId());
    }

    #[Test]
    public function load_working_copy_falls_back_to_find_when_no_revision_driver_is_wired(): void
    {
        $entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            revisionable: false,
        );

        $handler = new SqlSchemaHandler($entityType, $this->db);
        $handler->ensureTable();

        $resolver = new SingleConnectionResolver($this->db);
        $repo = new EntityRepository(
            $entityType,
            new SqlStorageDriver($resolver),
            $this->dispatcher,
            null,
            $this->db,
        );

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);

        $this->assertSame('v1', $repo->loadWorkingCopy('1')?->label());
        $this->assertSame($repo->find('1')?->label(), $repo->loadWorkingCopy('1')?->label());
    }
}
