<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALConsistentReadTransaction;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DBALTransaction;
use Waaseyaa\Database\Exception\TransactionCompletionException;
use Waaseyaa\Database\TransactionCompletionCoordinator;
use Waaseyaa\Database\TransactionCompletionInterface;

#[CoversClass(DBALTransaction::class)]
#[CoversClass(TransactionCompletionCoordinator::class)]
final class DBALTransactionTest extends TestCase
{
    private DBALDatabase $db;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
    }

    public function testCommit(): void
    {
        $tx = $this->db->transaction();

        $tx->commit();

        // Should not throw; transaction is completed.
        $this->assertTrue(true);
    }

    public function testRollBack(): void
    {
        $tx = $this->db->transaction();

        $tx->rollBack();

        // Should not throw; transaction is completed.
        $this->assertTrue(true);
    }

    public function testDoubleCommitThrows(): void
    {
        $tx = $this->db->transaction();
        $tx->commit();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transaction is no longer active.');

        $tx->commit();
    }

    public function testDoubleRollBackThrows(): void
    {
        $tx = $this->db->transaction();
        $tx->rollBack();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transaction is no longer active.');

        $tx->rollBack();
    }

    public function testCommitAfterRollBackThrows(): void
    {
        $tx = $this->db->transaction();
        $tx->rollBack();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transaction is no longer active.');

        $tx->commit();
    }

    public function testAfterCommitOnCompletedTransactionThrows(): void
    {
        $transaction = $this->db->transaction();
        $transaction->commit();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transaction is no longer active.');
        $transaction->afterCommit(static function (): void {});
    }

    public function testNestedCompletionRunsOnlyAfterManagedOuterCommit(): void
    {
        $calls = [];
        $outer = $this->db->transaction();
        $inner = $this->db->transaction();
        self::assertInstanceOf(TransactionCompletionInterface::class, $inner);
        $inner->afterCommit(function () use (&$calls): void {
            $calls[] = $this->db->getConnection()->getTransactionNestingLevel();
        });

        $inner->commit();
        self::assertSame([], $calls);

        $outer->commit();
        self::assertSame([0], $calls);
    }

    public function testManagedOuterRollbackDiscardsNestedCompletion(): void
    {
        $called = false;
        $outer = $this->db->transaction();
        $inner = $this->db->transaction();
        self::assertInstanceOf(TransactionCompletionInterface::class, $inner);
        $inner->afterCommit(static function () use (&$called): void {
            $called = true;
        });

        $inner->commit();
        $outer->rollBack();

        self::assertFalse($called);
    }

    public function testSeparateDatabaseWrappersShareTheConnectionCompletionStack(): void
    {
        $secondWrapper = new DBALDatabase($this->db->getConnection());
        $called = false;
        $outer = $this->db->transaction();
        $inner = $secondWrapper->transaction();
        self::assertInstanceOf(TransactionCompletionInterface::class, $inner);
        $inner->afterCommit(static function () use (&$called): void {
            $called = true;
        });

        $inner->commit();
        self::assertFalse($called);
        $outer->commit();
        self::assertTrue($called);
    }

    public function testCompletionFailureDoesNotStarveLaterCallbacks(): void
    {
        $called = false;
        $transaction = $this->db->transaction();
        self::assertInstanceOf(TransactionCompletionInterface::class, $transaction);
        $transaction->afterCommit(static function (): never {
            throw new \RuntimeException('first completion failed');
        });
        $transaction->afterCommit(static function () use (&$called): void {
            $called = true;
        });

        try {
            $transaction->commit();
            self::fail('Completion failures must be reported after all callbacks run.');
        } catch (TransactionCompletionException $failure) {
            self::assertCount(1, $failure->failures());
        }

        self::assertTrue($called);
        self::assertSame(0, $this->db->getConnection()->getTransactionNestingLevel());
    }

    public function testFrameworkTransactionRefusesUnmanagedDoctrineParent(): void
    {
        $connection = $this->db->getConnection();
        $connection->beginTransaction();

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Cannot nest a framework transaction inside an unmanaged Doctrine transaction');
            $this->db->transaction();
        } finally {
            $connection->rollBack();
        }
    }

    public function testManagedTransactionsMustCompleteInReverseOpenOrder(): void
    {
        $outer = $this->db->transaction();
        $inner = $this->db->transaction();

        try {
            $outer->commit();
            self::fail('The outer transaction completed while its inner frame was still open.');
        } catch (\LogicException $failure) {
            self::assertSame('Transactions must complete in last-opened, first-closed order.', $failure->getMessage());
        } finally {
            $inner->rollBack();
        }
    }

    public function testConsistentReadRestoresIsolationWhenTheIsolationChangeThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionIsolation')->willReturn(TransactionIsolationLevel::READ_COMMITTED);
        $connection->method('isTransactionActive')->willReturn(false);
        $calls = [];
        $connection->expects($this->exactly(2))
            ->method('setTransactionIsolation')
            ->willReturnCallback(static function (TransactionIsolationLevel $level) use (&$calls): void {
                $calls[] = $level;
                if (count($calls) === 1) {
                    throw new \RuntimeException('Platform rejected repeatable read.');
                }
            });

        try {
            new DBALConsistentReadTransaction($connection);
            self::fail('The failed isolation change must fail the consistent-read transaction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Platform rejected repeatable read.', $exception->getMessage());
            self::assertSame([
                TransactionIsolationLevel::REPEATABLE_READ,
                TransactionIsolationLevel::READ_COMMITTED,
            ], $calls);
        }
    }

    public function testConsistentReadClosesConnectionWhenCommitIsolationRestoreThrows(): void
    {
        $connection = $this->consistentReadConnectionWithFailingRestore();
        $connection->expects($this->once())->method('commit');
        $connection->expects($this->once())->method('close');
        $transaction = new DBALConsistentReadTransaction($connection);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Platform rejected isolation restoration.');
        $transaction->commit();
    }

    public function testConsistentReadClosesConnectionWhenRollbackIsolationRestoreThrows(): void
    {
        $connection = $this->consistentReadConnectionWithFailingRestore();
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->once())->method('close');
        $transaction = new DBALConsistentReadTransaction($connection);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Platform rejected isolation restoration.');
        $transaction->rollBack();
    }

    public function testConsistentReadRejectsAnActiveRepeatableReadTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionIsolation')->willReturn(TransactionIsolationLevel::REPEATABLE_READ);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->never())->method('beginTransaction');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A consistent read cannot start inside an active transaction.');
        new DBALConsistentReadTransaction($connection);
    }

    public function testConsistentReadClosesConnectionWhenBeginTransactionThrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionIsolation')->willReturn(TransactionIsolationLevel::READ_COMMITTED);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->exactly(2))->method('setTransactionIsolation');
        $connection->expects($this->once())
            ->method('beginTransaction')
            ->willThrowException(new \RuntimeException('Driver rejected transaction begin.'));
        $connection->expects($this->once())->method('close');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Driver rejected transaction begin.');
        new DBALConsistentReadTransaction($connection);
    }

    private function consistentReadConnectionWithFailingRestore(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionIsolation')->willReturn(TransactionIsolationLevel::READ_COMMITTED);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('beginTransaction');
        $calls = 0;
        $connection->expects($this->exactly(2))
            ->method('setTransactionIsolation')
            ->willReturnCallback(static function (TransactionIsolationLevel $level) use (&$calls): void {
                ++$calls;
                if ($calls === 1) {
                    self::assertSame(TransactionIsolationLevel::REPEATABLE_READ, $level);

                    return;
                }
                self::assertSame(TransactionIsolationLevel::READ_COMMITTED, $level);
                throw new \RuntimeException('Platform rejected isolation restoration.');
            });

        return $connection;
    }
}
