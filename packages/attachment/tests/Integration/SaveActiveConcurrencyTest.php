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
 * Concurrency test for the at-most-one-active invariant on the
 * AttachmentRepository::save() guard added in WP2 — the sibling of
 * {@see SetActiveConcurrencyTest}, which covers setActive() only.
 *
 * This test exercises a DIFFERENT scenario: N concurrent processes each
 * SAVE A BRAND NEW is_active=1 attachment for the SAME parent (never
 * calling setActive()). Each save() wraps "demote every existing active
 * sibling, then insert the new row" in one transaction — structurally
 * identical to setActive()'s two-write transaction — so the SAME
 * correctness argument applies: SQLite (WAL + busy_timeout) serializes
 * writers, so concurrent transactions on this single file-backed database
 * behave like some serial ordering of "demote-then-insert" operations, and
 * whichever commits last is the sole active row afterward.
 *
 * This guarantee is SINGLE-CONNECTION-PER-PROCESS-BUT-SHARED-DATABASE, which
 * is exactly what this harness exercises (many processes, one SQLite file).
 * It does NOT extend to the separate, NOT-fully-atomic generic entity-API
 * guard ({@see \Waaseyaa\Attachment\AttachmentActiveGuardListener}), whose
 * residual race is documented on that class and is deliberately NOT
 * asserted away here or anywhere else in this WP.
 */
#[CoversNothing]
#[RequiresPhpExtension('pcntl')]
final class SaveActiveConcurrencyTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped(
                'Requires pcntl extension and Linux-style fork. '
                . 'Run this test on the Linux CI matrix.',
            );
        }

        $this->dbPath = sys_get_temp_dir() . '/waaseyaa_attachment_save_concurrency_' . uniqid('', true) . '.sqlite';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }

        foreach (['-wal', '-shm'] as $suffix) {
            if (file_exists($this->dbPath . $suffix)) {
                @unlink($this->dbPath . $suffix);
            }
        }
    }

    /**
     * Under 30 concurrent AttachmentRepository::save() calls, each
     * inserting a brand-new is_active=1 attachment for the same parent,
     * exactly one attachment has is_active = 1 after all processes
     * complete.
     */
    #[Test]
    public function concurrentNewActiveSavesLeaveExactlyOneActiveRow(): void
    {
        $database = DBALDatabase::createSqlite($this->dbPath);
        $database->getConnection()->executeStatement('PRAGMA journal_mode=WAL');
        $database->getConnection()->executeStatement('PRAGMA busy_timeout=5000');

        $schema = new AttachmentSchema($database);
        $schema->ensureTable();

        unset($database);

        $processCount = 30;
        $pids = [];
        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                self::fail('pcntl_fork() failed — cannot run concurrency test.');
            }

            if ($pid === 0) {
                try {
                    $childDb = DBALDatabase::createSqlite($this->dbPath);
                    $childDb->getConnection()->executeStatement('PRAGMA journal_mode=WAL');
                    $childDb->getConnection()->executeStatement('PRAGMA busy_timeout=5000');

                    $entityType = EntityType::fromClass(Attachment::class);
                    $resolver = new SingleConnectionResolver($childDb);
                    $driver = new SqlStorageDriver($resolver, 'id');
                    $dispatcher = new EventDispatcher();

                    $entityRepository = new EntityRepository(
                        entityType: $entityType,
                        driver: $driver,
                        eventDispatcher: $dispatcher,
                    );

                    $repository = new AttachmentRepository(
                        entityRepository: $entityRepository,
                        database: $childDb,
                    );

                    $attachment = new Attachment([
                        'parent_entity_type' => 'node',
                        'parent_entity_id' => '1',
                        'is_active' => 1,
                        'filename' => "concurrent_{$i}.pdf",
                        'created_at' => time(),
                        'updated_at' => time(),
                    ]);
                    $attachment->enforceIsNew();
                    $repository->save($attachment);
                } catch (\Throwable) {
                    exit(1);
                }

                exit(0);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $childStatus);
        }

        $assertDb = DBALDatabase::createSqlite($this->dbPath);

        $allRows = iterator_to_array($assertDb->select('attachment')
            ->fields('attachment', ['id'])
            ->condition('parent_entity_type', 'node')
            ->condition('parent_entity_id', '1')
            ->execute());

        self::assertCount($processCount, $allRows, 'Setup sanity: all concurrent saves must have inserted a row.');

        $activeRows = iterator_to_array($assertDb->select('attachment')
            ->fields('attachment', ['id'])
            ->condition('parent_entity_type', 'node')
            ->condition('parent_entity_id', '1')
            ->condition('is_active', 1)
            ->execute());

        self::assertCount(
            1,
            $activeRows,
            'Exactly one attachment must be active after concurrent new-active saves.',
        );
    }
}
