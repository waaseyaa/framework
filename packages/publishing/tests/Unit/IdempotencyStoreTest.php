<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Exception\TransactionCompletionException;
use Waaseyaa\Database\TransactionCompletionInterface;
use Waaseyaa\Publishing\Exception\IdempotencyConflictException;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

#[CoversClass(IdempotencyStore::class)]
final class IdempotencyStoreTest extends TestCase
{
    private DBALDatabase $db;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::publishing($this->db);
        $this->db->schema()->createTable('idempotency_effects', [
            'fields' => [
                'effect_key' => ['type' => 'varchar', 'length' => 64, 'not null' => true],
            ],
            'primary key' => ['effect_key'],
        ]);
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
    public function the_same_client_key_is_independent_across_namespaces(): void
    {
        $store = $this->store();

        self::assertSame(
            ['surface' => 'page'],
            $store->execute('client-key', 'createDraft', ['slug' => 'same'], fn(): array => ['surface' => 'page'], 'node:page'),
        );
        self::assertSame(
            ['surface' => 'event'],
            $store->execute('client-key', 'createDraft', ['slug' => 'same'], fn(): array => ['surface' => 'event'], 'node:event'),
        );
        self::assertCount(2, iterator_to_array($this->db->select('publishing_idempotency')->execute()));
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
    public function a_post_mutation_failure_rolls_back_the_mutation_with_the_missing_replay_record(): void
    {
        $store = $this->store();

        try {
            $store->execute('k', 'op', ['a' => 1], function (): array {
                $this->db->query(
                    'INSERT INTO idempotency_effects (effect_key) VALUES (?)',
                    ['first-attempt'],
                );
                throw new \RuntimeException('serialization failed after mutation');
            });
            self::fail('Expected the post-mutation failure to propagate.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], iterator_to_array(
            $this->db->select('idempotency_effects')->execute(),
        ));

        $response = $store->execute('k', 'op', ['a' => 1], function (): array {
            $this->db->query(
                'INSERT INTO idempotency_effects (effect_key) VALUES (?)',
                ['retry'],
            );

            return ['ok' => true];
        });

        self::assertSame(['ok' => true], $response);
        self::assertCount(1, iterator_to_array(
            $this->db->select('idempotency_effects')->execute(),
        ));
    }

    #[Test]
    public function empty_keys_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->execute('  ', 'op', [], fn(): array => []);
    }

    #[Test]
    public function committed_idempotency_record_survives_completion_failure_and_replays_without_reexecuting(): void
    {
        $store = $this->store();
        $runs = 0;

        try {
            $store->execute('k', 'op', ['a' => 1], function () use (&$runs): array {
                $runs++;
                $this->db->query(
                    'INSERT INTO idempotency_effects (effect_key) VALUES (?)',
                    ['mutation-effect'],
                );
                $nested = $this->db->transaction();
                self::assertInstanceOf(TransactionCompletionInterface::class, $nested);
                $nested->afterCommit(static function (): void {
                    throw new \RuntimeException('post-commit side effect failed');
                });
                $nested->commit();

                return ['ok' => true, 'run' => $runs];
            });
            self::fail('Expected completion failure after commit.');
        } catch (TransactionCompletionException $failure) {
            self::assertCount(1, $failure->failures());
            self::assertSame('post-commit side effect failed', $failure->failures()[0]->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $failure->getPrevious());
        }

        self::assertSame(1, $runs);
        self::assertCount(1, iterator_to_array($this->db->select('publishing_idempotency')->execute()));
        self::assertCount(1, iterator_to_array($this->db->select('idempotency_effects')->execute()));

        self::assertSame(
            ['ok' => true, 'run' => 1],
            $store->execute('k', 'op', ['a' => 1], function () use (&$runs): array {
                $runs++;

                return ['ok' => false, 'run' => $runs];
            }),
        );
        self::assertSame(1, $runs);
    }

    #[Test]
    public function pre_commit_completion_exception_rolls_back_mutation_and_allows_retry(): void
    {
        $store = $this->store();
        $runs = 0;

        try {
            $store->execute('k', 'op', ['a' => 1], function () use (&$runs): array {
                $runs++;
                $this->db->query(
                    'INSERT INTO idempotency_effects (effect_key) VALUES (?)',
                    ['should-roll-back'],
                );
                throw new TransactionCompletionException([
                    new \RuntimeException('operation failed before commit'),
                ]);
            });
            self::fail('Expected operation failure.');
        } catch (TransactionCompletionException $failure) {
            self::assertSame('operation failed before commit', $failure->getPrevious()?->getMessage());
        }

        self::assertSame(1, $runs);
        self::assertCount(0, iterator_to_array($this->db->select('publishing_idempotency')->execute()));
        self::assertCount(0, iterator_to_array($this->db->select('idempotency_effects')->execute()));

        self::assertSame(['ok' => true], $store->execute('k', 'op', ['a' => 1], function () use (&$runs): array {
            $runs++;

            return ['ok' => true];
        }));
        self::assertSame(2, $runs);
    }

    #[Test]
    public function pre_commit_operation_failure_rolls_back_mutation_and_idempotency_and_allows_retry(): void
    {
        $store = $this->store();
        $runs = 0;

        try {
            $store->execute('k', 'op', ['a' => 1], function () use (&$runs): array {
                $runs++;
                $this->db->query(
                    'INSERT INTO idempotency_effects (effect_key) VALUES (?)',
                    ['should-roll-back'],
                );
                throw new \RuntimeException('operation failed before commit');
            });
            self::fail('Expected operation failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('operation failed before commit', $exception->getMessage());
            self::assertNotInstanceOf(TransactionCompletionException::class, $exception);
        }

        self::assertSame(1, $runs);
        self::assertCount(0, iterator_to_array($this->db->select('publishing_idempotency')->execute()));
        self::assertCount(0, iterator_to_array($this->db->select('idempotency_effects')->execute()));

        self::assertSame(['ok' => true], $store->execute('k', 'op', ['a' => 1], function () use (&$runs): array {
            $runs++;
            $this->db->query(
                'INSERT INTO idempotency_effects (effect_key) VALUES (?)',
                ['retry'],
            );

            return ['ok' => true];
        }));
        self::assertSame(2, $runs);
        self::assertCount(1, iterator_to_array($this->db->select('idempotency_effects')->execute()));
    }
}
