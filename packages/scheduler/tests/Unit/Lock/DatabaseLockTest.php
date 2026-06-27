<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Scheduler\Lock\DatabaseLock;

/**
 * Ownership-checked lock release — scheduler m15 (production-audit m15).
 *
 * Reproduces the split-brain double-run: a lease expires mid-run, a second node
 * reclaims it, and the first node's stale release must NOT delete the second
 * node's live lock.
 */
#[CoversClass(DatabaseLock::class)]
final class DatabaseLockTest extends TestCase
{
    private const TABLE = 'waaseyaa_schedule_locks';

    private DBALDatabase $db;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        // Mirror the migration DDL (incl. the m15 owner column).
        $this->db->getConnection()->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' (
                task_name VARCHAR(255) PRIMARY KEY,
                locked_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                locked_by VARCHAR(64) NOT NULL
            )',
        );
    }

    private function ownerOf(string $task): ?string
    {
        foreach (
            $this->db->select(self::TABLE, 'l')
                ->fields('l', ['locked_by'])
                ->condition('task_name', $task)
                ->execute() as $row
        ) {
            return (string) $row['locked_by'];
        }

        return null;
    }

    private function expireLease(string $task): void
    {
        $this->db->update(self::TABLE)
            ->fields(['expires_at' => time() - 1])
            ->condition('task_name', $task)
            ->execute();
    }

    #[Test]
    public function acquireReturnsTokenAndBlocksSecondAcquire(): void
    {
        $lock = new DatabaseLock($this->db);

        $token = $lock->acquire('task', 60);

        self::assertIsString($token);
        self::assertNotSame('', $token);
        // A live lock blocks a second acquirer.
        self::assertNull($lock->acquire('task', 60));
    }

    #[Test]
    public function staleReleaseDoesNotDeleteAnotherNodesReclaimedLock(): void
    {
        $nodeA = new DatabaseLock($this->db);
        $nodeB = new DatabaseLock($this->db);

        // Node A acquires and starts running.
        $tokenA = $nodeA->acquire('report', 60);
        self::assertIsString($tokenA);

        // Task overruns; A's lease expires mid-run.
        $this->expireLease('report');

        // Node B's acquire() cleans up the stale row and reclaims the lock.
        $tokenB = $nodeB->acquire('report', 60);
        self::assertIsString($tokenB);
        self::assertNotSame($tokenA, $tokenB, 'reclaim must mint a new owner token');
        self::assertSame($tokenB, $this->ownerOf('report'));

        // Node A finally finishes and releases — but it no longer owns the lock.
        $nodeA->release('report', $tokenA);

        // Node B's live lock MUST survive (no split-brain): the row is still
        // present and still owned by B, so a third node cannot acquire and run.
        self::assertSame($tokenB, $this->ownerOf('report'), "stale release must not delete B's live lock");
        self::assertNull((new DatabaseLock($this->db))->acquire('report', 60));
    }

    #[Test]
    public function ownerReleaseDeletesItsOwnRow(): void
    {
        $lock = new DatabaseLock($this->db);

        $token = $lock->acquire('task', 60);
        self::assertIsString($token);

        $lock->release('task', $token);

        self::assertNull($this->ownerOf('task'), 'owner release must delete the row');
        // Lock is free again.
        self::assertIsString($lock->acquire('task', 60));
    }

    #[Test]
    public function releaseWithWrongTokenDeletesNothing(): void
    {
        $lock = new DatabaseLock($this->db);
        $token = $lock->acquire('task', 60);
        self::assertIsString($token);

        $lock->release('task', 'not-the-owner-token');

        // Still held by the real owner.
        self::assertSame($token, $this->ownerOf('task'));
        self::assertNull($lock->acquire('task', 60));
    }
}
