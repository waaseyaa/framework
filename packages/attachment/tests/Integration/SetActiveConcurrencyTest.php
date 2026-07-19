<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\AttachmentRepository;
use Waaseyaa\Attachment\Schema\AttachmentSchema;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

/**
 * Concurrency test for the setActive at-most-one-active invariant (NFR-010).
 *
 * Exercises the guarantee that no matter how many concurrent callers invoke
 * setActive() for attachments of the same parent, exactly one row has
 * is_active = 1 when all calls complete.
 *
 * This test uses pcntl_fork() to spawn true OS processes that share a
 * file-backed SQLite database. It is skipped on platforms without pcntl
 * (Windows, environments without the extension).
 *
 * The correctness guarantee comes from the two-UPDATE transaction in
 * AttachmentRepository::setActive(): clear all siblings first, then set
 * the target. SQLite's serializable isolation ensures the pair is atomic.
 *
 * See: research.md Q6 (setActive atomicity rationale), NFR-010.
 *
 * @requires extension pcntl
 */
#[CoversNothing]
#[RequiresPhpExtension('pcntl')]
final class SetActiveConcurrencyTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped(
                'Requires pcntl extension and Linux-style fork. ' .
                'Run this test on the Linux CI matrix. ' .
                'The at-most-one-active invariant is unit-tested structurally ' .
                'by the transaction in AttachmentRepository::setActive().',
            );
        }

        $this->dbPath = sys_get_temp_dir() . '/waaseyaa_attachment_concurrency_' . uniqid('', true) . '.sqlite';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }

        // Clean up WAL journal files if they exist.
        foreach (['-wal', '-shm'] as $suffix) {
            if (file_exists($this->dbPath . $suffix)) {
                @unlink($this->dbPath . $suffix);
            }
        }
    }

    /**
     * NFR-010: under 50 concurrent setActive() calls, exactly one attachment
     * has is_active = 1 after all processes complete.
     *
     * See research.md Q6 for the atomicity rationale.
     */
    #[Test]
    public function setActiveInvariantHoldsUnderConcurrentCalls(): void
    {
        // ── Setup: file-backed SQLite with WAL mode ────────────────────────────
        // File-backed (not :memory:) so forked child processes share the data.
        $database = DBALDatabase::createSqlite($this->dbPath);

        // Enable WAL mode to reduce SQLITE_BUSY contention under concurrent writers.
        $database->getConnection()->executeStatement('PRAGMA journal_mode=WAL');
        $database->getConnection()->executeStatement('PRAGMA busy_timeout=5000');

        $schema = new AttachmentSchema($database);
        $schema->ensureTable();

        $entityType = EntityType::fromClass(Attachment::class);
        $resolver = new SingleConnectionResolver($database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $dispatcher = new EventDispatcher();

        $entityRepository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $dispatcher,
        );

        $repository = new AttachmentRepository(
            entityRepository: $entityRepository,
            database: $database,
        );

        // ── Insert 50 attachments for the same parent ──────────────────────────
        $attachmentIds = [];
        for ($i = 0; $i < 50; $i++) {
            $attachment = new Attachment([
                'parent_entity_type' => 'node',
                'parent_entity_id' => '1',
                'is_active' => 0,
                'filename' => "file_{$i}.pdf",
                'created_at' => time(),
                'updated_at' => time(),
            ]);
            $attachment->enforceIsNew();
            $repository->save($attachment);

            $attachmentIds[] = (string) $attachment->id();
        }

        self::assertCount(50, $attachmentIds, 'Setup: must have 50 attachments.');

        // Close the parent connection before forking — each child re-opens its own.
        unset($database, $entityRepository, $driver, $resolver, $repository);

        // ── Fork 50 child processes; each calls setActive on a random attachment ─
        $pids = [];
        for ($i = 0; $i < 50; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                self::fail('pcntl_fork() failed — cannot run concurrency test.');
            }

            if ($pid === 0) {
                // Child process: open its own database connection and call setActive.
                try {
                    $childDb = DBALDatabase::createSqlite($this->dbPath);
                    $childDb->getConnection()->executeStatement('PRAGMA journal_mode=WAL');
                    $childDb->getConnection()->executeStatement('PRAGMA busy_timeout=5000');

                    $childEntityType = EntityType::fromClass(Attachment::class);
                    $childResolver = new SingleConnectionResolver($childDb);
                    $childDriver = new SqlStorageDriver($childResolver, 'id');
                    $childDispatcher = new EventDispatcher();

                    $childEntityRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                        entityType: $childEntityType,
                        driver: $childDriver,
                        eventDispatcher: $childDispatcher,
                    );

                    $childRepo = new AttachmentRepository(
                        entityRepository: $childEntityRepo,
                        database: $childDb,
                    );

                    // Pick a random attachment from the shared list.
                    $targetId = $attachmentIds[array_rand($attachmentIds)];
                    $childRepo->setActive($targetId);
                } catch (\Throwable) {
                    // Child process: swallow exceptions, exit with non-zero for logging.
                    exit(1);
                }

                exit(0);
            }

            $pids[] = $pid;
        }

        // ── Parent waits for all children to finish ────────────────────────────
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $childStatus);
        }

        // ── Assert invariant: exactly one active attachment ────────────────────
        $assertDb = DBALDatabase::createSqlite($this->dbPath);

        $rows = iterator_to_array($assertDb->select('attachment')
            ->fields('attachment', ['id'])
            ->condition('parent_entity_type', 'node')
            ->condition('parent_entity_id', '1')
            ->condition('is_active', 1)
            ->execute());

        self::assertCount(
            1,
            $rows,
            'NFR-010: exactly one attachment must be active after 50 concurrent setActive() calls.',
        );
    }
}
