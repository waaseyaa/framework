<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Integration\RateLimit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Logging\Middleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\RateLimit\DatabaseRateLimiter;

#[CoversClass(DatabaseRateLimiter::class)]
final class DatabaseRateLimiterConcurrencyTest extends TestCase
{
    public static function windows(): iterable
    {
        yield 'first insert' => [null, 2, true];
        yield 'active increment' => [1000, 3, false];
        yield 'expired reset' => [0, 2, true];
    }

    #[DataProvider('windows')]
    public function test_each_concurrent_attempt_is_counted(?int $windowStart, int $expectedCount, bool $expectedAllowed): void
    {
        $this->withConnections(function (DBALDatabase $database, DBALDatabase $peer, AbstractLogger $logger) use ($windowStart, $expectedCount, $expectedAllowed): void {
            if ($windowStart !== null) {
                $peer->insert('rate_limit_windows')->values(['key' => 'synthetic', 'count' => 1, 'window_start' => $windowStart])->execute();
            }
            $limiter = new DatabaseRateLimiter($database, static fn(): int => 1000);
            $other = new DatabaseRateLimiter($peer, static fn(): int => 1000);
            $competing = null;
            // Worker B commits after worker A reads but before A writes.
            $logger->beforeWrite = static function () use ($other, &$competing): void {
                $competing = $other->attempt('synthetic', 2, 60);
            };
            $result = $limiter->attempt('synthetic', 2, 60);
            self::assertTrue($competing['allowed']);
            self::assertSame($expectedAllowed, $result['allowed']);
            self::assertSame(0, $result['remaining']);
            self::assertSame($expectedAllowed ? null : 60, $result['retryAfter']);
            self::assertSame($expectedCount, (int) $peer->getConnection()->fetchOne('SELECT "count" FROM rate_limit_windows'));
        });
    }

    public function test_retained_snapshot_lock_error_is_not_retried_or_swallowed(): void
    {
        $this->withConnections(function (DBALDatabase $database, DBALDatabase $peer, AbstractLogger $logger): void {
            foreach (['synthetic', 'other'] as $key) {
                $peer->insert('rate_limit_windows')->values(['key' => $key, 'count' => 1, 'window_start' => 1000])->execute();
            }
            $cursor = $database->select('rate_limit_windows')->execute();
            $cursor->rewind();
            $peer->update('rate_limit_windows')->fields(['count' => 2])->condition('key', 'other')->execute();
            $writes = 0;
            $logger->beforeWrite = static function () use (&$writes): void {
                ++$writes;
            };
            try {
                new DatabaseRateLimiter($database, static fn(): int => 1000)->attempt('synthetic', 2, 60);
                self::fail('A stale snapshot was silently accepted.');
            } catch (DriverException $failure) {
                self::assertStringContainsString('database is locked', $failure->getMessage());
                self::assertSame(1, $writes);
                self::assertSame(1, $logger->writeCount);
                self::assertSame(1, (int) $peer->getConnection()->fetchOne('SELECT "count" FROM rate_limit_windows WHERE "key" = ?', ['synthetic']));
            } finally {
                unset($cursor);
            }
        });
    }

    public function test_continuously_lost_updates_are_bounded_and_fail_closed(): void
    {
        $this->withConnections(function (DBALDatabase $database, DBALDatabase $peer, AbstractLogger $logger): void {
            $peer->insert('rate_limit_windows')->values(['key' => 'synthetic', 'count' => 1, 'window_start' => 1000])->execute();
            $other = new DatabaseRateLimiter($peer, static fn(): int => 1000);
            $competingWrites = 0;
            $callback = null;
            $callback = static function () use ($other, $logger, &$callback, &$competingWrites): void {
                ++$competingWrites;
                $other->attempt('synthetic', 1000, 60);
                $logger->beforeWrite = $callback;
            };
            $logger->beforeWrite = $callback;
            try {
                new DatabaseRateLimiter($database, static fn(): int => 1000)->attempt('synthetic', 1000, 60);
                self::fail('An unrecorded request was accepted.');
            } catch (\RuntimeException $failure) {
                self::assertStringContainsString('32 accounting attempts', $failure->getMessage());
                self::assertSame(32, $competingWrites);
                self::assertSame(33, (int) $peer->getConnection()->fetchOne('SELECT "count" FROM rate_limit_windows'));
            } finally {
                $logger->beforeWrite = null;
                $callback = null;
            }
        });
    }

    public function test_expired_zero_limit_preserves_reset_result(): void
    {
        $this->withConnections(function (DBALDatabase $database, DBALDatabase $peer): void {
            $peer->insert('rate_limit_windows')->values(['key' => 'synthetic', 'count' => 3, 'window_start' => 0])->execute();
            $result = new DatabaseRateLimiter($database, static fn(): int => 1000)->attempt('synthetic', 0, 60);
            self::assertSame(['allowed' => true, 'remaining' => -1, 'retryAfter' => null], $result);
            self::assertSame(1, (int) $peer->getConnection()->fetchOne('SELECT "count" FROM rate_limit_windows'));
            self::assertSame(1000, (int) $peer->getConnection()->fetchOne('SELECT window_start FROM rate_limit_windows'));
        });
    }

    public function test_active_transaction_preserves_original_insert_collision(): void
    {
        $this->withConnections(function (DBALDatabase $database, DBALDatabase $peer, AbstractLogger $logger): void {
            $database->getConnection()->beginTransaction();
            $logger->beforeWrite = static function () use ($database): void {
                $database->getConnection()->executeStatement('INSERT INTO rate_limit_windows ("key", "count", window_start) VALUES (?, ?, ?)', ['synthetic', 1, 1000]);
            };
            try {
                new DatabaseRateLimiter($database, static fn(): int => 1000)->attempt('synthetic', 2, 60);
                self::fail('A caller-owned transaction collision was replayed.');
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $failure) {
                self::assertStringContainsString('UNIQUE constraint', $failure->getMessage());
                self::assertSame(2, $logger->writeCount);
            } finally {
                $database->getConnection()->rollBack();
            }
        });
    }

    public function test_opaque_adapter_preserves_original_insert_collision(): void
    {
        $this->withConnections(function (DBALDatabase $database): void {
            $database->insert('rate_limit_windows')->values(['key' => 'synthetic', 'count' => 1, 'window_start' => 1000])->execute();
            try {
                $database->insert('rate_limit_windows')->values(['key' => 'synthetic', 'count' => 1, 'window_start' => 1000])->execute();
                self::fail('Fixture collision was not raised.');
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $collision) {
                $adapter = $this->createMock(\Waaseyaa\Database\DatabaseInterface::class);
                $adapter->method('schema')->willReturn($database->schema());
                $select = $this->createStub(\Waaseyaa\Database\SelectInterface::class);
                $select->method('condition')->willReturnSelf();
                $select->method('execute')->willReturn(new \ArrayIterator([]));
                $adapter->expects(self::once())->method('select')->willReturn($select);
                $insert = $this->createStub(\Waaseyaa\Database\InsertInterface::class);
                $insert->method('values')->willReturnSelf();
                $insert->method('execute')->willThrowException($collision);
                $adapter->expects(self::once())->method('insert')->willReturn($insert);
                try {
                    new DatabaseRateLimiter($adapter, static fn(): int => 1000)->attempt('synthetic', 2, 60);
                    self::fail('An opaque adapter collision was replayed.');
                } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $failure) {
                    self::assertSame($collision, $failure);
                }
            }
        });
    }

    private function withConnections(\Closure $case): void
    {
        $path = sys_get_temp_dir() . '/fw3183-rate-' . bin2hex(random_bytes(8)) . '.sqlite';
        $seed = DBALDatabase::createSqlite($path, 'production');
        $source = $seed->getConnection();
        $migration = require dirname(__DIR__, 3) . '/migrations/2026_08_12_000001_rate_limit_window_schema.php';
        $migration->up(new SchemaBuilder($source));
        $logger = new class extends AbstractLogger {
            public ?\Closure $beforeWrite = null;
            public int $writeCount = 0;

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (preg_match('/^(INSERT INTO|UPDATE).*rate_limit_windows/i', $context['sql'] ?? '') === 1) {
                    ++$this->writeCount;
                    if ($this->beforeWrite !== null) {
                        $callback = $this->beforeWrite;
                        $this->beforeWrite = null;
                        $callback();
                    }
                }
            }
        };
        $database = new DBALDatabase(new Connection(
            $source->getParams(),
            new Middleware($logger)->wrap($source->getDriver()),
            $source->getConfiguration(),
        ));
        $source->close();
        $peer = DBALDatabase::createSqlite($path, 'production');
        try {
            $case($database, $peer, $logger);
        } finally {
            $database->getConnection()->close();
            $peer->getConnection()->close();
            foreach (['', '-wal', '-shm'] as $suffix) {
                if (is_file($path . $suffix)) {
                    unlink($path . $suffix);
                }
            }
        }
    }
}
