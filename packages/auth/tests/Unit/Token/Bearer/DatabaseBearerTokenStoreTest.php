<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Token\Bearer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\Token\Bearer\BearerTokenRecord;
use Waaseyaa\Auth\Token\Bearer\BearerTokenStoreException;
use Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface;
use Waaseyaa\Auth\Token\Bearer\DatabaseBearerTokenStore;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use Waaseyaa\Entity\DateTime\EntityClockInterface;

#[CoversClass(DatabaseBearerTokenStore::class)]
final class DatabaseBearerTokenStoreTest extends TestCase
{
    public const string START = '2026-08-03 10:00:00.000000';

    private DBALDatabase $database;

    /** @var EntityClockInterface&object{now: \DateTimeImmutable} */
    private EntityClockInterface $clock;

    private DatabaseBearerTokenStore $store;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->clock = self::fixedClock();
        $this->store = new DatabaseBearerTokenStore($this->database, $this->clock);
    }

    /** @return EntityClockInterface&object{now: \DateTimeImmutable} */
    private static function fixedClock(): EntityClockInterface
    {
        return new class implements EntityClockInterface {
            public \DateTimeImmutable $now;

            public function __construct()
            {
                $this->now = new \DateTimeImmutable(DatabaseBearerTokenStoreTest::START, new \DateTimeZone('UTC'));
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };
    }

    private function advanceClock(int $seconds): void
    {
        $this->clock->now = $this->clock->now->modify(sprintf('+%d seconds', $seconds));
    }

    // ── Issuance ────────────────────────────────────────────────────────

    #[Test]
    public function issue_returns_a_secret_that_authenticates_and_a_full_record(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['present guided content'], 3600, 'ci-agent');

        self::assertMatchesRegularExpression('/^mbt_[0-9a-f]{16}\.[0-9a-f]{64}$/', $issued->secret);
        self::assertMatchesRegularExpression('/^mbt_[0-9a-f]{16}$/', $issued->record->id);
        self::assertSame(42, $issued->record->accountUid);
        self::assertSame('mcp:write', $issued->record->audience);
        self::assertSame(['present guided content'], $issued->record->scopes);
        self::assertSame('ci-agent', $issued->record->label);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $issued->record->fingerprint);
        self::assertNull($issued->record->revokedAt);
        self::assertNull($issued->record->rotatedFrom);
        self::assertEquals(
            new \DateTimeImmutable('2026-08-03 11:00:00.000000', new \DateTimeZone('UTC')),
            $issued->record->expiresAt,
        );

        $verified = $this->store->verify($issued->secret, 'mcp:write');
        self::assertInstanceOf(BearerTokenRecord::class, $verified);
        self::assertSame($issued->record->id, $verified->id);
        self::assertSame(42, $verified->accountUid);
    }

    #[Test]
    public function the_plaintext_secret_is_never_persisted(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['present guided content'], 3600, 'ci-agent');

        // The random 64-hex secret half must not appear in any stored column.
        $secretHalf = substr($issued->secret, strlen($issued->record->id) + 1);
        self::assertSame(64, strlen($secretHalf));

        foreach ($this->database->query('SELECT * FROM auth_bearer_token') as $row) {
            $rowJson = json_encode($row, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($secretHalf, $rowJson);
            self::assertStringNotContainsString($issued->secret, $rowJson);
        }
    }

    #[Test]
    public function issued_secrets_and_ids_are_unique_across_issuance(): void
    {
        $a = $this->store->issue(1, 'mcp:write', ['s'], 3600, '');
        $b = $this->store->issue(1, 'mcp:write', ['s'], 3600, '');

        self::assertNotSame($a->secret, $b->secret);
        self::assertNotSame($a->record->id, $b->record->id);
        self::assertNotSame($a->record->fingerprint, $b->record->fingerprint);
    }

    #[Test]
    public function issue_rejects_a_non_positive_account_uid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(0, 'mcp:write', ['s'], 3600, '');
    }

    #[Test]
    public function issue_rejects_the_dev_admin_sentinel_uid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(PHP_INT_MAX, 'mcp:write', ['s'], 3600, '');
    }

    #[Test]
    public function issue_rejects_ttl_below_the_minimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(42, 'mcp:write', ['s'], BearerTokenStoreInterface::MIN_TTL_SECONDS - 1, '');
    }

    #[Test]
    public function issue_rejects_ttl_above_the_maximum_lifetime(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(42, 'mcp:write', ['s'], BearerTokenStoreInterface::MAX_TTL_SECONDS + 1, '');
    }

    #[Test]
    public function issue_defaults_to_the_default_ttl(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s']);

        $expected = $this->clock->now->modify(
            sprintf('+%d seconds', BearerTokenStoreInterface::DEFAULT_TTL_SECONDS),
        );
        self::assertEquals($expected, $issued->record->expiresAt);
    }

    #[Test]
    public function issue_rejects_an_empty_scope_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(42, 'mcp:write', [], 3600, '');
    }

    #[Test]
    public function issue_rejects_a_non_string_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(42, 'mcp:write', [42], 3600, '');
    }

    #[Test]
    public function issue_rejects_a_blank_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(42, 'mcp:write', ['   '], 3600, '');
    }

    #[Test]
    public function issue_rejects_an_overlong_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(42, 'mcp:write', [str_repeat('a', 129)], 3600, '');
    }

    #[Test]
    public function issue_rejects_a_scope_with_control_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(42, 'mcp:write', ["evil\nscope"], 3600, '');
    }

    #[Test]
    public function issue_rejects_more_than_the_maximum_scope_count(): void
    {
        $scopes = array_map(static fn(int $i): string => 'scope-' . $i, range(1, 33));

        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(42, 'mcp:write', $scopes, 3600, '');
    }

    #[Test]
    public function scopes_are_canonicalized_trimmed_deduplicated_and_sorted(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['  b-scope ', 'a-scope', 'b-scope'], 3600, '');

        self::assertSame(['a-scope', 'b-scope'], $issued->record->scopes);

        $verified = $this->store->verify($issued->secret, 'mcp:write');
        self::assertNotNull($verified);
        self::assertSame(['a-scope', 'b-scope'], $verified->scopes);
    }

    #[Test]
    public function issue_rejects_a_malformed_audience(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(42, "mcp write\n", ['s'], 3600, '');
    }

    #[Test]
    public function issue_rejects_an_empty_audience(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(42, '', ['s'], 3600, '');
    }

    #[Test]
    public function issue_rejects_an_overlong_label(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->issue(42, 'mcp:write', ['s'], 3600, str_repeat('x', 129));
    }

    // ── Verification ────────────────────────────────────────────────────

    #[Test]
    public function verify_rejects_malformed_token_shapes_without_touching_storage(): void
    {
        $failing = $this->failingDatabase();
        $store = new DatabaseBearerTokenStore($failing, $this->clock);

        self::assertNull($store->verify('', 'mcp:write'));
        self::assertNull($store->verify('not-a-token', 'mcp:write'));
        self::assertNull($store->verify('mbt_short.deadbeef', 'mcp:write'));
        self::assertNull($store->verify(
            'mbt_0123456789abcdef.' . str_repeat('Z', 64),
            'mcp:write',
        ));
    }

    #[Test]
    public function verify_rejects_an_unknown_token_id(): void
    {
        $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        $unknown = 'mbt_00000000000000ff.' . str_repeat('0', 64);
        self::assertNull($this->store->verify($unknown, 'mcp:write'));
    }

    #[Test]
    public function verify_rejects_a_wrong_secret_for_a_known_id(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        $wrongSecret = $issued->record->id . '.' . str_repeat('0', 64);
        self::assertNull($this->store->verify($wrongSecret, 'mcp:write'));
    }

    #[Test]
    public function verify_enforces_the_audience_fail_closed(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        self::assertNull($this->store->verify($issued->secret, 'mcp:read'));
        self::assertNull($this->store->verify($issued->secret, 'other-service'));
        self::assertNotNull($this->store->verify($issued->secret, 'mcp:write'));
    }

    #[Test]
    public function verify_rejects_exactly_at_the_expiry_instant(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        $this->advanceClock(3599);
        self::assertNotNull($this->store->verify($issued->secret, 'mcp:write'));

        $this->advanceClock(1);
        self::assertNull($this->store->verify($issued->secret, 'mcp:write'));
    }

    #[Test]
    public function revoked_tokens_immediately_fail_verification(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        $this->store->revoke($issued->record->id);

        self::assertNull($this->store->verify($issued->secret, 'mcp:write'));
    }

    #[Test]
    public function revoke_is_idempotent_for_an_already_revoked_token(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        $this->store->revoke($issued->record->id);
        $this->store->revoke($issued->record->id);

        $record = $this->store->find($issued->record->id);
        self::assertNotNull($record);
        self::assertTrue($record->isRevoked());
    }

    #[Test]
    public function revoke_of_an_unknown_token_id_throws(): void
    {
        $this->expectException(BearerTokenStoreException::class);

        $this->store->revoke('mbt_00000000000000ff');
    }

    #[Test]
    public function verify_fails_closed_on_a_malformed_stored_record(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        $this->database->update('auth_bearer_token')
            ->fields(['scopes' => 'not-json'])
            ->condition('id', $issued->record->id)
            ->execute();

        self::assertNull($this->store->verify($issued->secret, 'mcp:write'));
    }

    #[Test]
    public function verify_fails_closed_on_a_truncated_stored_hash(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        $this->database->update('auth_bearer_token')
            ->fields(['secret_hash' => 'deadbeef'])
            ->condition('id', $issued->record->id)
            ->execute();

        self::assertNull($this->store->verify($issued->secret, 'mcp:write'));
    }

    #[Test]
    public function verify_returns_null_when_storage_is_unavailable(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        $store = new DatabaseBearerTokenStore($this->failingDatabase(), $this->clock);

        self::assertNull($store->verify($issued->secret, 'mcp:write'));
    }

    // ── Rotation ────────────────────────────────────────────────────────

    #[Test]
    public function rotate_issues_a_new_secret_and_invalidates_the_predecessor(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['a-scope'], 3600, 'ci-agent');

        $rotated = $this->store->rotate($issued->record->id);

        self::assertNotSame($issued->secret, $rotated->secret);
        self::assertSame(42, $rotated->record->accountUid);
        self::assertSame('mcp:write', $rotated->record->audience);
        self::assertSame(['a-scope'], $rotated->record->scopes);
        self::assertSame('ci-agent', $rotated->record->label);
        self::assertSame($issued->record->id, $rotated->record->rotatedFrom);

        self::assertNull($this->store->verify($issued->secret, 'mcp:write'), 'predecessor must be dead');
        self::assertNotNull($this->store->verify($rotated->secret, 'mcp:write'), 'successor must be live');
    }

    #[Test]
    public function rotate_grants_a_fresh_lifetime_equal_to_the_original_ttl(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        $this->advanceClock(1800);
        $rotated = $this->store->rotate($issued->record->id);

        self::assertEquals(
            $this->clock->now->modify('+3600 seconds'),
            $rotated->record->expiresAt,
        );
    }

    #[Test]
    public function rotate_refuses_a_revoked_token(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');
        $this->store->revoke($issued->record->id);

        $this->expectException(BearerTokenStoreException::class);

        $this->store->rotate($issued->record->id);
    }

    #[Test]
    public function rotate_refuses_an_expired_token(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');
        $this->advanceClock(3600);

        $this->expectException(BearerTokenStoreException::class);

        $this->store->rotate($issued->record->id);
    }

    #[Test]
    public function rotate_of_an_unknown_token_throws(): void
    {
        $this->expectException(BearerTokenStoreException::class);

        $this->store->rotate('mbt_00000000000000ff');
    }

    #[Test]
    public function a_failed_rotation_leaves_exactly_one_usable_credential(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        // The revocation UPDATE inside the rotation transaction fails: the
        // whole rotation must roll back — the new secret never becomes usable
        // and the predecessor keeps working. Two live credentials (or zero)
        // must be impossible.
        $sabotaged = new DatabaseBearerTokenStore(
            $this->updateFailingDatabase($this->database),
            $this->clock,
        );

        try {
            $sabotaged->rotate($issued->record->id);
            self::fail('The sabotaged rotation should have thrown.');
        } catch (BearerTokenStoreException) {
            // Expected.
        }

        self::assertNotNull($this->store->verify($issued->secret, 'mcp:write'), 'predecessor must survive');

        $rows = iterator_to_array($this->database->query('SELECT id FROM auth_bearer_token'));
        self::assertCount(1, $rows, 'the aborted successor row must not persist');
    }

    #[Test]
    public function issue_wraps_storage_failures_in_a_sanitized_store_exception(): void
    {
        $store = new DatabaseBearerTokenStore($this->failingDatabase(), $this->clock);

        try {
            $store->issue(42, 'mcp:write', ['s'], 3600, '');
            self::fail('Expected a BearerTokenStoreException.');
        } catch (BearerTokenStoreException $e) {
            self::assertStringNotContainsString('connection refused: secret dsn', $e->getMessage());
        }
    }

    // ── Listing / lookup ────────────────────────────────────────────────

    #[Test]
    public function find_returns_null_for_an_unknown_id_and_the_record_for_a_known_one(): void
    {
        self::assertNull($this->store->find('mbt_00000000000000ff'));

        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, 'ci-agent');
        $record = $this->store->find($issued->record->id);

        self::assertNotNull($record);
        self::assertSame('ci-agent', $record->label);
    }

    #[Test]
    public function all_lists_records_without_any_secret_material(): void
    {
        $a = $this->store->issue(1, 'mcp:write', ['s'], 3600, 'first');
        $b = $this->store->issue(2, 'mcp:write', ['s'], 3600, 'second');

        $records = $this->store->all();

        self::assertCount(2, $records);
        $json = json_encode($records, JSON_THROW_ON_ERROR);
        foreach ([$a, $b] as $issued) {
            $secretHalf = substr($issued->secret, strlen($issued->record->id) + 1);
            self::assertStringNotContainsString($secretHalf, $json);
        }
    }

    // ── Test doubles ────────────────────────────────────────────────────

    /** A database whose every operation fails with an infrastructure error. */
    private function failingDatabase(): DatabaseInterface
    {
        return new class implements DatabaseInterface {
            private function boom(): never
            {
                throw new \RuntimeException('connection refused: secret dsn');
            }

            public function select(string $table, string $alias = ''): SelectInterface
            {
                $this->boom();
            }

            public function insert(string $table): InsertInterface
            {
                $this->boom();
            }

            public function update(string $table): UpdateInterface
            {
                $this->boom();
            }

            public function delete(string $table): DeleteInterface
            {
                $this->boom();
            }

            public function schema(): SchemaInterface
            {
                $this->boom();
            }

            public function transaction(string $name = ''): TransactionInterface
            {
                $this->boom();
            }

            public function query(string $sql, array $args = []): \Traversable
            {
                $this->boom();
            }

            public function quoteIdentifier(string $identifier): string
            {
                $this->boom();
            }
        };
    }

    /** A delegating database whose UPDATE builder always fails at execute(). */
    private function updateFailingDatabase(DatabaseInterface $inner): DatabaseInterface
    {
        return new class($inner) implements DatabaseInterface {
            public function __construct(private readonly DatabaseInterface $inner) {}

            public function select(string $table, string $alias = ''): SelectInterface
            {
                return $this->inner->select($table, $alias);
            }

            public function insert(string $table): InsertInterface
            {
                return $this->inner->insert($table);
            }

            public function update(string $table): UpdateInterface
            {
                return new class implements UpdateInterface {
                    public function fields(array $fields): static
                    {
                        return $this;
                    }

                    public function condition(string $field, mixed $value, string $operator = '='): static
                    {
                        return $this;
                    }

                    public function execute(): int
                    {
                        throw new \RuntimeException('simulated update failure');
                    }
                };
            }

            public function delete(string $table): DeleteInterface
            {
                return $this->inner->delete($table);
            }

            public function schema(): SchemaInterface
            {
                return $this->inner->schema();
            }

            public function transaction(string $name = ''): TransactionInterface
            {
                return $this->inner->transaction($name);
            }

            public function query(string $sql, array $args = []): \Traversable
            {
                return $this->inner->query($sql, $args);
            }

            public function quoteIdentifier(string $identifier): string
            {
                return $this->inner->quoteIdentifier($identifier);
            }
        };
    }
}
