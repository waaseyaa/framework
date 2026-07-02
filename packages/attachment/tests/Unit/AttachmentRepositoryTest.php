<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\AttachmentNotFoundException;
use Waaseyaa\Attachment\AttachmentRepository;
use Waaseyaa\Attachment\Schema\AttachmentSchema;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(AttachmentRepository::class)]
#[CoversClass(Attachment::class)]
#[CoversClass(AttachmentSchema::class)]
#[CoversClass(AttachmentNotFoundException::class)]
final class AttachmentRepositoryTest extends TestCase
{
    private DBALDatabase $database;

    private EntityRepository $entityRepository;

    private AttachmentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = DBALDatabase::createSqlite();

        // Ensure schema exists.
        $schema = new AttachmentSchema($this->database);
        $schema->ensureTable();

        $entityType = EntityType::fromClass(Attachment::class);

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $dispatcher = new EventDispatcher();

        $this->entityRepository = new EntityRepository(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $dispatcher,
        );

        $this->repository = new AttachmentRepository(
            entityRepository: $this->entityRepository,
            database: $this->database,
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Creates and saves an Attachment, returns the saved entity loaded from storage.
     */
    private function makeAttachment(
        string $parentType,
        string $parentId,
        int $isActive = 0,
        string $filename = 'test.pdf',
    ): Attachment {
        $attachment = new Attachment([
            'parent_entity_type' => $parentType,
            'parent_entity_id' => $parentId,
            'is_active' => $isActive,
            'filename' => $filename,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        // enforceIsNew() ensures INSERT even without a pre-set ID.
        $attachment->enforceIsNew();
        $this->repository->save($attachment);

        // Reload from storage so we have the auto-assigned id.
        $id = (string) $attachment->id();
        $loaded = $this->entityRepository->find($id);
        self::assertInstanceOf(Attachment::class, $loaded);

        return $loaded;
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    #[Test]
    public function saveAndFindReturnsSameEntity(): void
    {
        $saved = $this->makeAttachment('node', '1');
        $id = (string) $saved->id();

        $found = $this->entityRepository->find($id);
        self::assertInstanceOf(Attachment::class, $found);
        self::assertSame($id, (string) $found->id());
        self::assertSame('node', $found->get('parent_entity_type'));
        self::assertSame('1', $found->get('parent_entity_id'));
    }

    #[Test]
    public function listForReturnsAttachmentsInIdAscOrder(): void
    {
        $a = $this->makeAttachment('node', '1', 0, 'a.pdf');
        $b = $this->makeAttachment('node', '1', 0, 'b.pdf');
        // Different parent — must not appear.
        $this->makeAttachment('node', '2', 0, 'c.pdf');

        $list = $this->repository->listFor('node', '1');

        self::assertCount(2, $list);
        self::assertSame((string) $a->id(), (string) $list[0]->id());
        self::assertSame((string) $b->id(), (string) $list[1]->id());
    }

    #[Test]
    public function getActiveReturnsNullWhenNoneActive(): void
    {
        $this->makeAttachment('node', '1', 0, 'inactive.pdf');

        $result = $this->repository->getActive('node', '1');
        self::assertNull($result);
    }

    #[Test]
    public function getActiveReturnsActiveAttachment(): void
    {
        $this->makeAttachment('node', '1', 0, 'inactive.pdf');
        $active = $this->makeAttachment('node', '1', 1, 'active.pdf');

        $result = $this->repository->getActive('node', '1');
        self::assertInstanceOf(Attachment::class, $result);
        self::assertSame((string) $active->id(), (string) $result->id());
    }

    #[Test]
    public function setActiveFlipsActiveFlag(): void
    {
        $a = $this->makeAttachment('node', '1', 0, 'a.pdf');
        $b = $this->makeAttachment('node', '1', 0, 'b.pdf');

        $this->repository->setActive((string) $a->id());

        $reloadedA = $this->entityRepository->find((string) $a->id());
        $reloadedB = $this->entityRepository->find((string) $b->id());
        self::assertInstanceOf(Attachment::class, $reloadedA);
        self::assertInstanceOf(Attachment::class, $reloadedB);

        self::assertSame(1, (int) $reloadedA->get('is_active'), 'Target must be active');
        self::assertSame(0, (int) $reloadedB->get('is_active'), 'Sibling must be inactive');
    }

    #[Test]
    public function setActiveTransfersActiveFlagFromPreviousToNew(): void
    {
        $a = $this->makeAttachment('node', '1', 1, 'a.pdf');
        $b = $this->makeAttachment('node', '1', 0, 'b.pdf');

        $this->repository->setActive((string) $b->id());

        $reloadedA = $this->entityRepository->find((string) $a->id());
        $reloadedB = $this->entityRepository->find((string) $b->id());
        self::assertInstanceOf(Attachment::class, $reloadedA);
        self::assertInstanceOf(Attachment::class, $reloadedB);

        self::assertSame(0, (int) $reloadedA->get('is_active'), 'Previously active must now be inactive');
        self::assertSame(1, (int) $reloadedB->get('is_active'), 'Target must now be active');
    }

    /**
     * setActive() issues two raw UPDATEs (demote siblings, activate target)
     * that must both stamp `updated_at` — otherwise a row's audit trail
     * freezes at whatever value the last EntityRepository::save() wrote,
     * even though setActive() just changed its `is_active` state.
     */
    #[Test]
    public function setActiveStampsUpdatedAtOnBothTheDemotedSiblingAndTheActivatedTarget(): void
    {
        $staleTimestamp = 1_000;
        $a = $this->makeAttachment('node', '1', 1, 'a.pdf');
        $b = $this->makeAttachment('node', '1', 0, 'b.pdf');

        // Force both rows to a known-stale updated_at via a raw UPDATE — the
        // entity repository's save() path (used by makeAttachment()) does
        // not itself auto-stamp updated_at, so this simulates a row last
        // touched some time ago.
        $this->database->update('attachment')
            ->fields(['updated_at' => $staleTimestamp])
            ->condition('id', (string) $a->id())
            ->execute();
        $this->database->update('attachment')
            ->fields(['updated_at' => $staleTimestamp])
            ->condition('id', (string) $b->id())
            ->execute();

        $before = time();
        $this->repository->setActive((string) $b->id());

        $rows = iterator_to_array($this->database->select('attachment')
            ->fields('attachment', ['id', 'updated_at'])
            ->condition('parent_entity_type', 'node')
            ->condition('parent_entity_id', '1')
            ->execute());

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertGreaterThanOrEqual(
                $before,
                (int) $row['updated_at'],
                "Row {$row['id']} updated_at must be bumped from the stale seed value.",
            );
        }
    }

    #[Test]
    public function setActiveOnNonExistentIdThrowsNotFoundException(): void
    {
        $this->expectException(AttachmentNotFoundException::class);
        $this->repository->setActive('99999');
    }

    #[Test]
    public function setActiveDoesNotAffectOtherParents(): void
    {
        $parent1 = $this->makeAttachment('node', '1', 0, 'p1.pdf');
        $parent2 = $this->makeAttachment('node', '2', 1, 'p2.pdf');

        $this->repository->setActive((string) $parent1->id());

        // parent2's attachment must remain active.
        $reloadedP2 = $this->entityRepository->find((string) $parent2->id());
        self::assertInstanceOf(Attachment::class, $reloadedP2);
        self::assertSame(1, (int) $reloadedP2->get('is_active'), 'Other parent attachment must remain active');
    }

    #[Test]
    public function deleteRemovesAttachment(): void
    {
        $attachment = $this->makeAttachment('node', '1');
        $id = (string) $attachment->id();

        $this->repository->delete($id);

        $found = $this->entityRepository->find($id);
        self::assertNull($found);
    }

    #[Test]
    public function deleteOnNonExistentIdIsNoOp(): void
    {
        // Should not throw.
        $this->repository->delete('99999');
        self::assertTrue(true); // Reached without exception.
    }

    // ── At-most-one-active invariant (WP2) ───────────────────────────────────

    /**
     * Direct AttachmentRepository::save() of a second is_active=1 attachment
     * alongside an existing active row must demote the first — the
     * invariant holds even when the caller never touches setActive().
     */
    #[Test]
    public function saveOfSecondActiveAttachmentDemotesThePreviousActive(): void
    {
        $first = $this->makeAttachment('node', '1', 1, 'first.pdf');

        $second = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '1',
            'is_active' => 1,
            'filename' => 'second.pdf',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $second->enforceIsNew();
        $this->repository->save($second);

        $activeRows = iterator_to_array($this->database->select('attachment')
            ->fields('attachment', ['id'])
            ->condition('parent_entity_type', 'node')
            ->condition('parent_entity_id', '1')
            ->condition('is_active', 1)
            ->execute());

        self::assertCount(1, $activeRows, 'Exactly one active attachment must remain after save().');
        self::assertSame((string) $second->id(), (string) $activeRows[0]['id']);

        $reloadedFirst = $this->entityRepository->find((string) $first->id());
        self::assertInstanceOf(Attachment::class, $reloadedFirst);
        self::assertSame(0, (int) $reloadedFirst->get('is_active'), 'Previously active attachment must be demoted.');
    }

    /**
     * Saving an inactive attachment must not touch siblings or open a
     * transaction guard — identical to pre-fix behavior.
     */
    #[Test]
    public function saveOfInactiveAttachmentDoesNotTouchSiblings(): void
    {
        $active = $this->makeAttachment('node', '1', 1, 'active.pdf');

        $inactive = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '1',
            'is_active' => 0,
            'filename' => 'inactive2.pdf',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $inactive->enforceIsNew();
        $this->repository->save($inactive);

        $reloadedActive = $this->entityRepository->find((string) $active->id());
        self::assertInstanceOf(Attachment::class, $reloadedActive);
        self::assertSame(1, (int) $reloadedActive->get('is_active'), 'Existing active attachment must be unaffected.');
    }

    /**
     * getActive() must detect (and log) a multi-active state manufactured by
     * bypassing every save-path guard via a raw DatabaseInterface UPDATE —
     * simulating the residual cross-process race the guards do not fully
     * close — and still return a deterministic winner (highest id).
     *
     * The SQLite partial unique index (AttachmentSchema) actually PREVENTS
     * this exact bypass once it exists — that is the point of adding it.
     * This test drops the index first to reach the state it exists to guard
     * against on platforms where it is unavailable: MySQL/MariaDB (no
     * partial-index support at all) and a pre-existing install whose data
     * already violated the invariant before the index could be created
     * (AttachmentSchema logs a warning and skips index creation rather than
     * failing install in that case — see ensureActivePartialUniqueIndex()).
     * getActive() detection is what actually covers those cases.
     */
    #[Test]
    public function getActiveDetectsAndLogsMultipleActiveRowsAndReturnsDeterministicWinner(): void
    {
        $first = $this->makeAttachment('node', '1', 1, 'first.pdf');
        $second = $this->makeAttachment('node', '1', 0, 'second.pdf');

        // Simulate a platform/legacy install without the partial unique
        // index (see docblock above) so the raw bypass below is reachable.
        $this->database->schema()->dropIndex('attachment', 'attachment_one_active_per_parent');

        // Bypass every guard: flip $second active directly via raw SQL,
        // manufacturing the invariant-violated state.
        $this->database->update('attachment')
            ->fields(['is_active' => 1])
            ->condition('id', (string) $second->id())
            ->execute();

        $spyLogger = new SpyLogger();
        $repository = new AttachmentRepository(
            entityRepository: $this->entityRepository,
            database: $this->database,
            logger: $spyLogger,
        );

        $result = $repository->getActive('node', '1');

        self::assertInstanceOf(Attachment::class, $result);
        self::assertSame(
            (string) $second->id(),
            (string) $result->id(),
            'Higher id (newest) must win deterministically.',
        );

        self::assertCount(1, $spyLogger->errors, 'Multi-active state must be logged at ERROR.');
        self::assertStringContainsString('node', $spyLogger->errors[0]);
        self::assertStringContainsString((string) $first->id(), $spyLogger->errors[0]);
        self::assertStringContainsString((string) $second->id(), $spyLogger->errors[0]);
    }

    /**
     * The single-active happy path must never log — no false positives.
     */
    #[Test]
    public function getActiveDoesNotLogWhenExactlyOneActive(): void
    {
        $this->makeAttachment('node', '1', 0, 'inactive.pdf');
        $this->makeAttachment('node', '1', 1, 'active.pdf');

        $spyLogger = new SpyLogger();
        $repository = new AttachmentRepository(
            entityRepository: $this->entityRepository,
            database: $this->database,
            logger: $spyLogger,
        );

        $repository->getActive('node', '1');

        self::assertSame([], $spyLogger->errors);
    }
}

/**
 * In-memory spy logger that records `error` calls (with rendered message
 * text — the multi-active detection message interpolates the parent and ids
 * directly into the message, not via PSR-3 placeholders).
 */
final class SpyLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $errors = [];

    public function emergency(string|\Stringable $message, array $context = []): void {}

    public function alert(string|\Stringable $message, array $context = []): void {}

    public function critical(string|\Stringable $message, array $context = []): void {}

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->errors[] = (string) $message;
    }

    public function warning(string|\Stringable $message, array $context = []): void {}

    public function notice(string|\Stringable $message, array $context = []): void {}

    public function info(string|\Stringable $message, array $context = []): void {}

    public function debug(string|\Stringable $message, array $context = []): void {}

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::Error) {
            $this->errors[] = (string) $message;
        }
    }
}
