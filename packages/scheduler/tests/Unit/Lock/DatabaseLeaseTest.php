<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit\Lock;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Scheduler\Lease\DatabaseLease;

final class DatabaseLeaseTest extends TestCase
{
    #[Test]
    public function infrastructureFailureIsNotReportedAsOverlap(): void
    {
        $lease = new DatabaseLease(DBALDatabase::createSqlite());

        $this->expectException(\Throwable::class);
        $lease->acquire('classification-retention', 30_000);
    }

    #[Test]
    public function acquisitionReturnsStableDomainGlobalFenceAndRenewableHandle(): void
    {
        $database = $this->databaseWithLeaseSchema();
        $lease = new DatabaseLease($database);

        $first = $lease->acquire('classification-retention', 30_000);
        self::assertNotNull($first);
        self::assertSame('classification-retention', $first->domain);
        self::assertSame(1, $first->fence);
        self::assertSame(1, $first->renewalGeneration);
        self::assertNull($lease->acquire('classification-retention', 30_000));

        $renewed = $lease->renew($first, 30_000);
        self::assertSame($first->fence, $renewed->fence);
        self::assertSame(2, $renewed->renewalGeneration);
        self::assertNotSame($first->renewalNonce, $renewed->renewalNonce);
        self::assertGreaterThan($first->expiresAtMs, $renewed->expiresAtMs);
    }

    #[Test]
    public function releaseRetainsFenceHistoryAndNextDomainGetsGlobalSuccessor(): void
    {
        $database = $this->databaseWithLeaseSchema();
        $lease = new DatabaseLease($database);

        $first = $lease->acquire('one', 30_000);
        self::assertNotNull($first);
        $lease->release($first);

        $second = $lease->acquire('two', 30_000);
        self::assertNotNull($second);
        self::assertSame($first->fence + 1, $second->fence);

        $reacquired = $lease->acquire('one', 30_000);
        self::assertNotNull($reacquired);
        self::assertGreaterThan($first->fence, $reacquired->fence);
    }

    private function databaseWithLeaseSchema(): DBALDatabase
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE waaseyaa_scheduler_fence_sequence (
                singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1),
                next_fence INTEGER NOT NULL
            )
            SQL);
        $database->getConnection()->executeStatement(
            'INSERT INTO waaseyaa_scheduler_fence_sequence (singleton_id, next_fence) VALUES (1, 1)',
        );
        $database->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE waaseyaa_scheduler_leases (
                lease_domain VARCHAR(255) PRIMARY KEY,
                owner_token VARCHAR(64) NULL,
                expires_at_ms INTEGER NOT NULL,
                fencing_token INTEGER NOT NULL,
                renewal_generation INTEGER NOT NULL,
                renewal_nonce VARCHAR(64) NULL
            )
            SQL);

        return $database;
    }
}
