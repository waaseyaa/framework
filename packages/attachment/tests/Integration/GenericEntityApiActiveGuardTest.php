<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\AttachmentActiveGuardListener;
use Waaseyaa\Attachment\Maintenance\AttachmentMaintenanceFieldReader;
use Waaseyaa\Attachment\Schema\AttachmentSchema;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;

/**
 * Proves the at-most-one-active invariant holds when a caller bypasses
 * {@see \Waaseyaa\Attachment\AttachmentRepository} entirely and saves
 * through the generic entity API (`getRepository('attachment')->save()`) —
 * the exact hole WP2 closes.
 *
 * Wires {@see AttachmentActiveGuardListener} onto the SAME dispatcher
 * concrete type ({@see SymfonyEventDispatcherAdapter}) and the same event
 * NAME (`EntityEvents::PRE_SAVE->value`) that
 * `AttachmentServiceProvider::boot()` uses in production, so a wiring
 * regression (wrong event name, wrong dispatcher method) fails this test the
 * way it would fail production — mirroring the #1852 wiring-fidelity
 * pattern.
 */
#[CoversNothing]
final class GenericEntityApiActiveGuardTest extends TestCase
{
    #[Test]
    public function savingSecondActiveViaGenericEntityApiDemotesTheFirst(): void
    {
        $database = DBALDatabase::createSqlite();
        new AttachmentSchema($database)->ensureTable();

        $entityType = EntityType::fromClass(Attachment::class);
        $resolver = new SingleConnectionResolver($database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $dispatcher = new SymfonyEventDispatcherAdapter();

        // Production wiring (AttachmentServiceProvider::boot()): the guard
        // listener is registered on EntityEvents::PRE_SAVE->value.
        $dispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            new AttachmentActiveGuardListener($database),
        );

        $entityRepository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $dispatcher,
        );

        $first = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '1',
            'is_active' => 1,
            'filename' => 'first.pdf',
        ]);
        $first->enforceIsNew();
        // Bypasses AttachmentRepository entirely — the generic entity API.
        $entityRepository->save($first);

        $second = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '1',
            'is_active' => 1,
            'filename' => 'second.pdf',
        ]);
        $second->enforceIsNew();
        $entityRepository->save($second);

        $activeRows = iterator_to_array($database->select('attachment')
            ->fields('attachment', ['id'])
            ->condition('parent_entity_type', 'node')
            ->condition('parent_entity_id', '1')
            ->condition('is_active', 1)
            ->execute());

        self::assertCount(1, $activeRows, 'Exactly one active attachment must remain.');
        self::assertSame((string) $second->id(), (string) $activeRows[0]['id']);

        $reloadedFirst = $entityRepository->find((string) $first->id());
        self::assertInstanceOf(Attachment::class, $reloadedFirst);
        self::assertSame(0, (int) new AttachmentMaintenanceFieldReader()->read($reloadedFirst)->active, 'First attachment must be demoted.');
    }

    /**
     * Without the listener wired (e.g. a fresh EventDispatcher with no
     * registration — the pre-fix state), the generic entity API leaves two
     * active rows. This is the RED-state pin: it documents the hole the
     * listener closes, distinct from proving the listener closes it.
     *
     * The partial unique index dropped below is the SQLite/PostgreSQL-only
     * backstop (AttachmentSchema::ensureActivePartialUniqueIndex()) — it is
     * dropped here to reach the platform-independent state this scenario
     * represents: MySQL/MariaDB (no partial-index support), or a
     * pre-existing install where index creation was skipped because the
     * data already violated the invariant. On those, the listener is the
     * ONLY thing standing between the generic entity API and a silent
     * multi-active row.
     */
    #[Test]
    public function withoutTheListenerWiredTheInvariantIsNotEnforced(): void
    {
        $database = DBALDatabase::createSqlite();
        new AttachmentSchema($database)->ensureTable();
        $database->schema()->dropIndex('attachment', 'attachment_one_active_per_parent');

        $entityType = EntityType::fromClass(Attachment::class);
        $resolver = new SingleConnectionResolver($database);
        $driver = new SqlStorageDriver($resolver, 'id');
        // No listener registered — simulates a boot() that failed to wire.
        $dispatcher = new SymfonyEventDispatcherAdapter();

        $entityRepository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $dispatcher,
        );

        foreach (['first.pdf', 'second.pdf'] as $filename) {
            $attachment = new Attachment([
                'parent_entity_type' => 'node',
                'parent_entity_id' => '1',
                'is_active' => 1,
                'filename' => $filename,
            ]);
            $attachment->enforceIsNew();
            $entityRepository->save($attachment);
        }

        $activeRows = iterator_to_array($database->select('attachment')
            ->fields('attachment', ['id'])
            ->condition('parent_entity_type', 'node')
            ->condition('parent_entity_id', '1')
            ->condition('is_active', 1)
            ->execute());

        self::assertCount(2, $activeRows, 'Without the guard wired, both rows stay active — documents the hole.');
    }

    /**
     * Complementary pin: WITH the partial unique index present (SQLite's
     * default, materialized by AttachmentSchema::ensureTable()) but WITHOUT
     * the listener wired, the second concurrent-ish active save does NOT
     * silently succeed — it throws a unique-constraint violation. The index
     * turns the listener's residual race (documented on
     * {@see AttachmentActiveGuardListener}) from "silent data corruption"
     * into "loud failure" on platforms where the index exists. This is a
     * real, load-bearing difference in behavior between SQLite/PostgreSQL
     * and MySQL/MariaDB, so it is pinned explicitly rather than asserted
     * away by the two tests above.
     */
    #[Test]
    public function withoutTheListenerButWithThePartialIndexTheSecondActiveSaveFailsLoudly(): void
    {
        $database = DBALDatabase::createSqlite();
        new AttachmentSchema($database)->ensureTable();

        $entityType = EntityType::fromClass(Attachment::class);
        $resolver = new SingleConnectionResolver($database);
        $driver = new SqlStorageDriver($resolver, 'id');
        // No listener registered — simulates a boot() that failed to wire.
        $dispatcher = new SymfonyEventDispatcherAdapter();

        $entityRepository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $dispatcher,
        );

        $first = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '1',
            'is_active' => 1,
            'filename' => 'first.pdf',
        ]);
        $first->enforceIsNew();
        $entityRepository->save($first);

        $second = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '1',
            'is_active' => 1,
            'filename' => 'second.pdf',
        ]);
        $second->enforceIsNew();

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $entityRepository->save($second);
    }

    /**
     * saveMany() — the batch surface (adversarial-review BLOCKER, WP2
     * follow-up). saveMany() runs the batch through one UnitOfWork; since
     * the PRE-write dispatch fix in EntityRepository, PRE_SAVE fires
     * IMMEDIATELY inside the batch transaction (not buffered post-commit),
     * so the guard listener demotes prior batch rows before each next
     * insert and the batch converges to sequential-save semantics: exactly
     * one active row, last in batch wins, no unique-constraint trip from
     * the partial index, no post-commit cross-demote.
     *
     * NOTE the in-memory/DB desync for the batch loser: $first's in-memory
     * object still says is_active=1 after saveMany() while its row says 0 —
     * the SAME desync a pair of sequential save() calls produces (the demote
     * is a direct UPDATE; no entity events fire for demoted siblings, by
     * design, mirroring setActive()). Callers needing fresh state must
     * re-find(). Acceptable and documented, not a bug.
     */
    #[Test]
    public function saveManyOfTwoActiveAttachmentsConvergesToOneActiveRow(): void
    {
        $database = DBALDatabase::createSqlite();
        new AttachmentSchema($database)->ensureTable();

        $entityRepository = $this->buildGuardedRepository($database);

        [$first, $second] = $this->twoNewActiveAttachments();
        $entityRepository->saveMany([$first, $second]);

        $this->assertConvergedToSingleActive($database, $second);
    }

    /**
     * Same batch scenario WITHOUT the partial unique index — the
     * MySQL/MariaDB shape (no partial-index support) and the legacy-install
     * shape (index creation skipped over pre-existing violations). The
     * immediate-PRE-dispatch guard alone must converge the batch; before
     * the dispatch fix, the two buffered listeners fired post-commit and
     * CROSS-DEMOTED each other (each demotes all-except-own-id), leaving
     * ZERO active rows.
     */
    #[Test]
    public function saveManyConvergesWithoutThePartialIndexToo(): void
    {
        $database = DBALDatabase::createSqlite();
        new AttachmentSchema($database)->ensureTable();
        $database->schema()->dropIndex('attachment', 'attachment_one_active_per_parent');

        $entityRepository = $this->buildGuardedRepository($database);

        [$first, $second] = $this->twoNewActiveAttachments();
        $entityRepository->saveMany([$first, $second]);

        $this->assertConvergedToSingleActive($database, $second);
    }

    /**
     * Builds an EntityRepository with the guard listener wired
     * production-style (same event name AttachmentServiceProvider::boot()
     * registers on).
     */
    private function buildGuardedRepository(DBALDatabase $database): EntityRepository
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $dispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            new AttachmentActiveGuardListener($database),
        );

        return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            entityType: EntityType::fromClass(Attachment::class),
            driver: new SqlStorageDriver(new SingleConnectionResolver($database), 'id'),
            eventDispatcher: $dispatcher,
            database: $database,
        );
    }

    /**
     * @return array{Attachment, Attachment}
     */
    private function twoNewActiveAttachments(): array
    {
        $attachments = [];
        foreach (['first.pdf', 'second.pdf'] as $filename) {
            $attachment = new Attachment([
                'parent_entity_type' => 'node',
                'parent_entity_id' => '1',
                'is_active' => 1,
                'filename' => $filename,
            ]);
            $attachment->enforceIsNew();
            $attachments[] = $attachment;
        }

        return [$attachments[0], $attachments[1]];
    }

    private function assertConvergedToSingleActive(DBALDatabase $database, Attachment $expectedWinner): void
    {
        $allRows = iterator_to_array($database->select('attachment')
            ->fields('attachment', ['id', 'is_active'])
            ->condition('parent_entity_type', 'node')
            ->condition('parent_entity_id', '1')
            ->execute());
        self::assertCount(2, $allRows, 'Both batch entities must have been persisted (no rollback, no lost rows).');

        $activeIds = array_values(array_map(
            static fn(array $row): string => (string) $row['id'],
            array_filter($allRows, static fn(array $row): bool => (int) $row['is_active'] === 1),
        ));

        self::assertSame(
            [(string) $expectedWinner->id()],
            $activeIds,
            'Exactly one active row must remain, and it must be the LAST entity in the batch.',
        );
    }
}
