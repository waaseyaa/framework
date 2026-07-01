<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Scheduler\Lock\InMemoryLock;

/**
 * InMemoryLock ownership parity with DatabaseLock — scheduler m15.
 */
#[CoversClass(InMemoryLock::class)]
final class InMemoryLockTest extends TestCase
{
    #[Test]
    public function acquireReturnsTokenAndBlocksSecondAcquire(): void
    {
        $lock = new InMemoryLock();

        $token = $lock->acquire('task', 60);

        self::assertIsString($token);
        self::assertNotSame('', $token);
        self::assertNull($lock->acquire('task', 60));
    }

    #[Test]
    public function releaseWithWrongTokenIsNoOp(): void
    {
        $lock = new InMemoryLock();
        $token = $lock->acquire('task', 60);
        self::assertIsString($token);

        // A non-owner release must NOT free the lock.
        $lock->release('task', 'not-the-owner-token');
        self::assertNull($lock->acquire('task', 60), 'wrong-token release must leave the lock held');

        // The real owner can release it.
        $lock->release('task', $token);
        self::assertIsString($lock->acquire('task', 60), 'owner release must free the lock');
    }

    #[Test]
    public function staleReleaseDoesNotFreeAReclaimedLock(): void
    {
        $lock = new InMemoryLock();

        // ttl=0 makes the lease immediately reclaimable (no sleeping / no clock).
        $tokenA = $lock->acquire('task', 0);
        self::assertIsString($tokenA);

        // A second acquire reclaims it with a fresh token (lease expired).
        $tokenB = $lock->acquire('task', 300);
        self::assertIsString($tokenB);
        self::assertNotSame($tokenA, $tokenB);

        // The first holder's stale release must not tear down B's live lock.
        $lock->release('task', $tokenA);
        self::assertNull($lock->acquire('task', 60), "stale release must not free B's reclaimed lock");

        // B owns it and can release it.
        $lock->release('task', $tokenB);
        self::assertIsString($lock->acquire('task', 60));
    }

    #[Test]
    public function injectedNowGovernsExpiryInsteadOfSystemTime(): void
    {
        $lock = new InMemoryLock();

        // Acquire a lock "in the past" (Unix epoch) with a 10-second TTL.
        // Its expires_at = epoch + 10, which is long expired relative to now.
        $past = new \DateTimeImmutable('@0'); // Unix epoch
        $tokenA = $lock->acquire('task', 10, $past);
        self::assertIsString($tokenA, 'first acquire must succeed');

        // A second acquire with a "present" timestamp well past the expiry
        // (epoch+11) must reclaim the expired lock.
        $future = new \DateTimeImmutable('@11');
        $tokenB = $lock->acquire('task', 300, $future);
        self::assertIsString($tokenB, 'acquire with injected now past expiry must succeed');
        self::assertNotSame($tokenA, $tokenB, 'reclaim must mint a new token');
    }
}
