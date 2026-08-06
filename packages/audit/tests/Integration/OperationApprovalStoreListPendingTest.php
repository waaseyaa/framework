<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Storage\ApprovalEventSchema;
use Waaseyaa\Audit\Writer\DatabaseOperationApprovalStore;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequest;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStatus;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;
use Waaseyaa\Testing\Clock\MutableEntityClock;

#[CoversNothing]
final class OperationApprovalStoreListPendingTest extends TestCase
{
    private const string RAW_SECRET = 'hunter2-raw-secret-value';
    public const string START = '2026-08-03 10:00:00.000000';

    private DBALDatabase $database;

    /** @var list<string> SELECT statements observed by the counting decorator */
    private array $observedSelects = [];

    private MutableEntityClock $clock;

    private DatabaseOperationApprovalStore $store;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        new ApprovalEventSchema($this->database)->ensure();
        $this->clock = new MutableEntityClock(new \DateTimeImmutable(self::START, new \DateTimeZone('UTC')));
        $this->store = new DatabaseOperationApprovalStore($this->countingDatabase(), $this->clock, ttlSeconds: 900);
    }

    #[Test]
    public function list_pending_defaults_to_a_page_of_50_in_ascending_requested_order(): void
    {
        $opened = $this->openMany(60);

        $page = $this->store->listPending();

        self::assertCount(50, $page->requests);
        self::assertNotNull($page->nextCursor);
        self::assertSame(
            \array_slice($opened, 0, 50),
            array_map(static fn (ApprovalRequest $request): string => $request->id, $page->requests),
        );

        $rest = $this->store->listPending(cursor: $page->nextCursor);
        self::assertCount(10, $rest->requests);
        self::assertNull($rest->nextCursor);
        self::assertSame(
            \array_slice($opened, 50),
            array_map(static fn (ApprovalRequest $request): string => $request->id, $rest->requests),
        );
    }

    #[Test]
    public function list_pending_honours_a_custom_limit(): void
    {
        $opened = $this->openMany(5);

        $page = $this->store->listPending(limit: 2);

        self::assertCount(2, $page->requests);
        self::assertNotNull($page->nextCursor);
        self::assertSame(\array_slice($opened, 0, 2), $this->idsOf($page->requests));
    }

    #[Test]
    public function list_pending_accepts_the_maximum_limit_of_100(): void
    {
        $opened = $this->openMany(3);

        $page = $this->store->listPending(limit: 100);

        self::assertSame($opened, $this->idsOf($page->requests));
        self::assertNull($page->nextCursor);
    }

    #[Test]
    public function list_pending_rejects_a_zero_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->listPending(limit: 0);
    }

    #[Test]
    public function list_pending_rejects_a_limit_above_100(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->listPending(limit: 101);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedCursors(): iterable
    {
        yield 'empty string' => [''];
        yield 'not base64url alphabet' => ['cursor with spaces!!'];
        yield 'raw unencoded payload' => ['apv1:5'];
        yield 'undecodable base64' => ['-----'];
        yield 'wrong structure' => [self::base64url('garbage')];
        yield 'wrong version' => [self::base64url('apv2:5')];
        yield 'missing id' => [self::base64url('apv1:')];
        yield 'zero id' => [self::base64url('apv1:0')];
        yield 'leading-zero id' => [self::base64url('apv1:05')];
        yield 'non-numeric id' => [self::base64url('apv1:abc')];
        yield 'trailing garbage' => [self::base64url('apv1:5:extra')];
        yield 'negative id' => [self::base64url('apv1:-5')];
        // base64_encode('apv1:12') ends in '=='; canonical cursors strip padding.
        yield 'non-canonical padding' => [strtr(base64_encode('apv1:12'), '+/', '-_')];
    }

    #[Test]
    #[DataProvider('malformedCursors')]
    public function list_pending_rejects_a_malformed_cursor_before_any_query(string $cursor): void
    {
        $this->openMany(2);
        $this->observedSelects = [];

        try {
            $this->store->listPending(cursor: $cursor);
            self::fail('A malformed cursor must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], $this->observedSelects, 'A malformed cursor must be rejected before the store is queried.');
    }

    #[Test]
    public function list_pending_omits_expired_approved_denied_and_consumed_requests(): void
    {
        $expired = $this->open(1);
        $this->clock->advance(new \DateInterval('PT900S'));

        $pending = $this->open(2);
        $approved = $this->open(3);
        $this->store->decide($approved, approved: true, operatorUid: 42);
        $denied = $this->open(4);
        $this->store->decide($denied, approved: false, operatorUid: 42);
        $consumed = $this->open(5);
        $this->store->decide($consumed, approved: true, operatorUid: 42);
        self::assertTrue($this->store->consume($consumed, 'receipt-1', 'corr-retry'));

        $page = $this->store->listPending();

        self::assertSame([$pending], $this->idsOf($page->requests));
        self::assertNull($page->nextCursor);
        self::assertNotSame($expired, $pending);
    }

    #[Test]
    public function list_pending_omits_a_request_at_its_exact_expiry_instant(): void
    {
        $id = $this->open(1);
        $request = $this->store->find($id);
        self::assertNotNull($request);

        $this->clock->set($request->expiresAt->modify('-1 microsecond'));
        self::assertSame([$id], $this->idsOf($this->store->listPending()->requests));

        $this->clock->set($request->expiresAt);
        self::assertSame([], $this->store->listPending()->requests);
    }

    #[Test]
    public function list_pending_fills_a_page_across_filtered_gaps(): void
    {
        $opened = $this->openMany(12);
        // Deny everything except positions 2, 6, 9, 10 and 11 — the survivors
        // sit on both sides of long filtered gaps.
        $surviving = [];
        foreach ($opened as $position => $id) {
            if (\in_array($position, [2, 6, 9, 10, 11], true)) {
                $surviving[] = $id;
                continue;
            }
            $this->store->decide($id, approved: false, operatorUid: 42);
        }

        $page = $this->store->listPending(limit: 3);

        self::assertSame(\array_slice($surviving, 0, 3), $this->idsOf($page->requests));
        self::assertNotNull($page->nextCursor);

        $rest = $this->store->listPending(limit: 3, cursor: $page->nextCursor);
        self::assertSame(\array_slice($surviving, 3), $this->idsOf($rest->requests));
        self::assertNull($rest->nextCursor);
    }

    #[Test]
    public function a_cursor_pointing_at_the_end_yields_an_empty_terminal_page(): void
    {
        $opened = $this->openMany(4);

        $page = $this->store->listPending(limit: 3);
        self::assertNotNull($page->nextCursor);

        // The only request beyond the cursor is decided away before page two.
        $this->store->decide($opened[3], approved: false, operatorUid: 42);

        $terminal = $this->store->listPending(limit: 3, cursor: $page->nextCursor);
        self::assertSame([], $terminal->requests);
        self::assertNull($terminal->nextCursor);
    }

    #[Test]
    public function full_traversal_is_exact_with_no_duplicates_or_omissions(): void
    {
        $opened = $this->openMany(7);

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->store->listPending(limit: 3, cursor: $cursor);
            $seen = array_merge($seen, $this->idsOf($page->requests));
            $cursor = $page->nextCursor;
            ++$pages;
        } while ($cursor !== null && $pages < 10);

        self::assertSame($opened, $seen);
        self::assertSame($opened, array_values(array_unique($seen)));
    }

    #[Test]
    public function requests_opened_after_a_page_appear_on_later_pages(): void
    {
        $opened = $this->openMany(3);

        $page = $this->store->listPending(limit: 2);
        self::assertSame(\array_slice($opened, 0, 2), $this->idsOf($page->requests));
        self::assertNotNull($page->nextCursor);

        // Traversal is live, not a snapshot: a request opened between pages
        // appends with a higher row id and joins the remaining traversal.
        $late = $this->open(99);

        $rest = $this->store->listPending(limit: 2, cursor: $page->nextCursor);
        self::assertSame([$opened[2], $late], $this->idsOf($rest->requests));
        self::assertNull($rest->nextCursor);
    }

    #[Test]
    public function pages_carry_safe_arguments_only_and_never_the_raw_secret(): void
    {
        $this->store->open(
            ApprovalTuple::forCall('token:ab12', 'mcp.write', 'node_delete', [
                'id' => 7,
                'password' => self::RAW_SECRET,
            ]),
            'corr-1',
            ['id' => 7, 'password' => '[redacted]'],
        );

        $page = $this->store->listPending();

        self::assertCount(1, $page->requests);
        self::assertSame(['id' => 7, 'password' => '[redacted]'], $page->requests[0]->safeArguments);
        $serialized = json_encode($page->requests, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::RAW_SECRET, $serialized);
    }

    #[Test]
    public function list_pending_issues_only_bounded_chunk_queries(): void
    {
        $opened = $this->openMany(30);
        foreach (\array_slice($opened, 0, 20) as $id) {
            $this->store->decide($id, approved: false, operatorUid: 42);
        }
        $this->observedSelects = [];

        $page = $this->store->listPending(limit: 5);

        self::assertCount(5, $page->requests);
        self::assertNotSame([], $this->observedSelects);
        self::assertLessThanOrEqual(2, \count($this->observedSelects), 'Filtering must happen in the bounded chunk query, not by fanning out per row.');
        foreach ($this->observedSelects as $sql) {
            self::assertMatchesRegularExpression('/\bLIMIT\s+\d+/i', $sql, 'Every pending-page SELECT must be bounded: ' . $sql);
        }
    }

    private static function base64url(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /** @param list<ApprovalRequest> $requests @return list<string> */
    private function idsOf(array $requests): array
    {
        return array_map(static fn (ApprovalRequest $request): string => $request->id, $requests);
    }

    private function open(int $seq): string
    {
        return $this->store->open(
            ApprovalTuple::forCall('token:ab12', 'mcp.write', 'node_delete', ['seq' => $seq]),
            sprintf('corr-%d', $seq),
            ['seq' => $seq],
        )->id;
    }

    /** @return list<string> request ids in requested order */
    private function openMany(int $count): array
    {
        $ids = [];
        for ($seq = 1; $seq <= $count; ++$seq) {
            $ids[] = $this->open($seq);
        }

        return $ids;
    }

    /**
     * A pass-through decorator that records every raw SELECT so the tests can
     * prove the pending scan stays bounded and never fans out per row.
     */
    private function countingDatabase(): DatabaseInterface
    {
        $test = $this;

        return new class($this->database, $test) implements DatabaseInterface {
            public function __construct(
                private readonly DatabaseInterface $inner,
                private readonly OperationApprovalStoreListPendingTest $test,
            ) {}

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
                return $this->inner->update($table);
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
                if (preg_match('/^\s*SELECT\b/i', $sql) === 1) {
                    $this->test->recordSelect($sql);
                }

                return $this->inner->query($sql, $args);
            }

            public function quoteIdentifier(string $identifier): string
            {
                return $this->inner->quoteIdentifier($identifier);
            }
        };
    }

    /** @internal recording hook for the counting decorator */
    public function recordSelect(string $sql): void
    {
        $this->observedSelects[] = $sql;
    }
}
