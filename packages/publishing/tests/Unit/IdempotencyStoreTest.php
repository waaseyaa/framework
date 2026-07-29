<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Publishing\Exception\IdempotencyConflictException;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;

#[CoversClass(IdempotencyStore::class)]
final class IdempotencyStoreTest extends TestCase
{
    private DBALDatabase $db;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
    }

    private function store(int $ttl = 3600): IdempotencyStore
    {
        return new IdempotencyStore($this->db, $ttl, fn(): int => $this->now);
    }

    #[Test]
    public function same_key_same_request_replays_without_reexecuting(): void
    {
        $store = $this->store();
        $runs = 0;
        $op = function () use (&$runs): array {
            $runs++;
            return ['run' => $runs];
        };

        self::assertSame(['run' => 1], $store->execute('k', 'op', ['a' => 1, 'b' => 2], $op));
        self::assertSame(['run' => 1], $store->execute('k', 'op', ['b' => 2, 'a' => 1], $op)); // key order canonicalized
        self::assertSame(1, $runs);
    }

    #[Test]
    public function same_key_different_request_conflicts(): void
    {
        $store = $this->store();
        $store->execute('k', 'op', ['a' => 1], fn(): array => ['ok' => true]);

        $this->expectException(IdempotencyConflictException::class);
        $store->execute('k', 'op', ['a' => 2], fn(): array => ['ok' => true]);
    }

    #[Test]
    public function same_key_different_operation_conflicts(): void
    {
        $store = $this->store();
        $store->execute('k', 'publish', ['a' => 1], fn(): array => ['ok' => true]);

        $this->expectException(IdempotencyConflictException::class);
        $store->execute('k', 'unpublish', ['a' => 1], fn(): array => ['ok' => true]);
    }

    #[Test]
    public function entries_expire_after_the_ttl(): void
    {
        $store = $this->store(ttl: 100);
        $runs = 0;
        $op = function () use (&$runs): array {
            $runs++;
            return ['run' => $runs];
        };

        $store->execute('k', 'op', ['a' => 1], $op);
        $this->now += 101;
        self::assertSame(['run' => 2], $store->execute('k', 'op', ['a' => 1], $op));
        self::assertSame(2, $runs);
    }

    #[Test]
    public function a_failed_operation_stores_nothing_and_can_be_retried(): void
    {
        $store = $this->store();
        try {
            $store->execute('k', 'op', ['a' => 1], fn(): array => throw new \RuntimeException('boom'));
            self::fail('Expected the operation failure to propagate.');
        } catch (\RuntimeException) {
        }

        self::assertSame(['ok' => true], $store->execute('k', 'op', ['a' => 1], fn(): array => ['ok' => true]));
    }

    #[Test]
    public function empty_keys_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->execute('  ', 'op', [], fn(): array => []);
    }
}
