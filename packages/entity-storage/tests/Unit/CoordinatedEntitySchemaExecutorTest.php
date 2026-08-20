<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\CoordinatedEntitySchemaExecutor;

/**
 * Planning puts the connection into `PRAGMA query_only` and marks it as
 * planning. A refusal is the expected way out, but a transition can also fail
 * for its own reasons, and that must leave nothing behind: a connection stuck
 * in query-only mode would make every later write fail, and a stale planning
 * mark would let nested schema work skip the coordinator. (#2452)
 */
#[CoversClass(CoordinatedEntitySchemaExecutor::class)]
final class CoordinatedEntitySchemaExecutorTest extends TestCase
{
    #[Test]
    public function a_plan_that_fails_for_its_own_reasons_leaves_a_writable_connection(): void
    {
        $database = DBALDatabase::createSqlite();
        $executor = new CoordinatedEntitySchemaExecutor($database);

        $caught = null;
        try {
            $executor->requiresMutation(static function (): void {
                throw new \RuntimeException('the transition itself failed');
            });
        } catch (\Throwable $failure) {
            $caught = $failure;
        }

        $this->assertInstanceOf(\RuntimeException::class, $caught, 'the failure must reach the caller unchanged');
        $this->assertSame('the transition itself failed', $caught->getMessage());
        $this->assertFalse($executor->isActive(), 'a failed plan must not leave the connection marked as planning');
        $this->assertSame(
            0,
            (int) $database->getConnection()->fetchOne('PRAGMA query_only'),
            'a failed plan must restore the connection it borrowed',
        );

        $database->schema()->createTable('written_after_a_failed_plan', ['fields' => ['id' => ['type' => 'int']]]);
        $this->assertTrue($database->schema()->tableExists('written_after_a_failed_plan'));
    }

    #[Test]
    public function a_plan_that_fails_preserves_a_callers_query_only_mode(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement('PRAGMA query_only = ON');
        $executor = new CoordinatedEntitySchemaExecutor($database);

        try {
            $executor->requiresMutation(static function (): void {
                throw new \LogicException('the transition itself failed');
            });
        } catch (\LogicException) {
            // Asserted by the state checks below.
        }

        $this->assertSame(
            1,
            (int) $database->getConnection()->fetchOne('PRAGMA query_only'),
            'a caller that was already query-only must stay query-only',
        );
        $this->assertFalse($executor->isActive());
    }
}
