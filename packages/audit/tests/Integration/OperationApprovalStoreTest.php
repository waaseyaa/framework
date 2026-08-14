<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Storage\ApprovalEventSchema;
use Waaseyaa\Audit\Writer\DatabaseOperationApprovalStore;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Audit\Approval\ApprovalAlreadyDecidedException;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStatus;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStoreException;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;
use Waaseyaa\Testing\Clock\MutableEntityClock;
use Waaseyaa\Testing\Database\TemporarySqliteDatabase;

#[CoversNothing]
final class OperationApprovalStoreTest extends TestCase
{
    private const string RAW_SECRET = 'hunter2-raw-secret-value';
    public const string START = '2026-08-03 10:00:00.000000';

    private TemporarySqliteDatabase $databaseFixture;

    private DatabaseInterface $database;

    private MutableEntityClock $clock;

    private DatabaseOperationApprovalStore $store;

    protected function setUp(): void
    {
        $this->databaseFixture = new TemporarySqliteDatabase();
        $this->database = $this->databaseFixture->database();
        RuntimeSchemaMigrations::audit($this->database);
        $this->clock = new MutableEntityClock(new \DateTimeImmutable(self::START, new \DateTimeZone('UTC')));
        $this->store = new DatabaseOperationApprovalStore($this->database, $this->clock, ttlSeconds: 900);
    }

    protected function tearDown(): void
    {
        $this->databaseFixture->remove();
        parent::tearDown();
    }

    #[Test]
    public function open_persists_the_exact_tuple_with_safe_arguments_and_no_raw_secret(): void
    {
        $tuple = $this->tuple();

        $request = $this->store->open($tuple, 'corr-original', ['title' => 'A', 'password' => '[redacted]']);

        self::assertMatchesRegularExpression('/^apr_[0-9a-f]{32}$/', $request->id);
        self::assertSame(ApprovalStatus::Pending, $request->status);
        self::assertSame('corr-original', $request->correlationId);
        self::assertSame(['title' => 'A', 'password' => '[redacted]'], $request->safeArguments);
        self::assertSame($tuple->requestKey, $request->tuple->requestKey);
        self::assertEquals(
            new \DateTimeImmutable('2026-08-03 10:15:00.000000', new \DateTimeZone('UTC')),
            $request->expiresAt,
        );

        $rows = iterator_to_array($this->database->query('SELECT * FROM mcp_approval_event'));
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('requested', $row['event_type']);
        self::assertSame($tuple->principalKey, $row['principal_key']);
        self::assertSame($tuple->surface, $row['surface']);
        self::assertSame($tuple->operation, $row['operation']);
        self::assertSame($tuple->argumentsFingerprint, $row['arguments_fingerprint']);
        self::assertSame($tuple->requestKey, $row['request_key']);
        self::assertSame('corr-original', $row['correlation_id']);
        self::assertStringNotContainsString(self::RAW_SECRET, json_encode($row, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function open_reuses_an_unexpired_undecided_pending_request_with_an_identical_request_key(): void
    {
        $first = $this->store->open($this->tuple(), 'corr-original', ['title' => 'A']);
        $retry = $this->store->open($this->tuple(), 'corr-retry', ['title' => 'A']);

        self::assertSame($first->id, $retry->id);
        // The reused request keeps the ORIGINAL correlation; the retry's own
        // correlation is recorded when (and only when) the approval is consumed.
        self::assertSame('corr-original', $retry->correlationId);

        $different = $this->store->open(
            ApprovalTuple::forCall('token:ab12', 'mcp.write', 'node_update', ['id' => 8]),
            'corr-other',
            ['id' => 8],
        );
        self::assertNotSame($first->id, $different->id);
    }

    #[Test]
    public function open_does_not_reuse_expired_or_already_decided_requests(): void
    {
        $expired = $this->store->open($this->tuple(), 'corr-1', []);
        $this->clock->advance(new \DateInterval('PT900S'));
        $afterExpiry = $this->store->open($this->tuple(), 'corr-2', []);
        self::assertNotSame($expired->id, $afterExpiry->id);

        $this->store->decide($afterExpiry->id, approved: false, operatorUid: 42);
        $afterDenial = $this->store->open($this->tuple(), 'corr-3', []);
        self::assertNotSame($afterExpiry->id, $afterDenial->id);
        self::assertSame(ApprovalStatus::Pending, $afterDenial->status);
    }

    #[Test]
    public function find_uses_the_exact_request_id_only(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);

        self::assertNotNull($this->store->find($request->id));
        self::assertNull($this->store->find('apr_' . str_repeat('0', 32)));
        self::assertNull($this->store->find('nonsense'));
    }

    #[Test]
    public function find_derives_expiry_honestly_at_the_exact_boundary(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);

        $this->clock->set($request->expiresAt->modify('-1 microsecond'));
        self::assertSame(ApprovalStatus::Pending, $this->store->find($request->id)?->status);

        $this->clock->set($request->expiresAt);
        self::assertSame(ApprovalStatus::Expired, $this->store->find($request->id)?->status);
    }

    #[Test]
    public function decide_records_approval_with_the_server_derived_operator_uid(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);

        $approved = $this->store->decide($request->id, approved: true, operatorUid: 42);

        self::assertSame(ApprovalStatus::Approved, $approved->status);
        self::assertSame(42, $approved->decidedByUid);
        self::assertNotNull($approved->decidedAt);

        $rows = iterator_to_array($this->database->query(
            'SELECT decision, operator_uid FROM mcp_approval_event WHERE event_type = :type',
            ['type' => 'decided'],
        ));
        self::assertSame([['decision' => 'approved', 'operator_uid' => 42]], $rows);
    }

    #[Test]
    public function decide_records_denial(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);

        $denied = $this->store->decide($request->id, approved: false, operatorUid: 42);

        self::assertSame(ApprovalStatus::Denied, $denied->status);
        self::assertSame(42, $denied->decidedByUid);
    }

    #[Test]
    public function decide_round_trips_an_approval_reason_as_durable_evidence(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', ['password' => '[redacted]']);

        $approved = $this->store->decide(
            $request->id,
            approved: true,
            operatorUid: 42,
            reason: '  Confirmed with the requester in ticket WSY-88.  ',
        );

        self::assertSame('Confirmed with the requester in ticket WSY-88.', $approved->decisionReason);
        self::assertSame(
            'Confirmed with the requester in ticket WSY-88.',
            $this->store->find($request->id)?->decisionReason,
        );

        $rows = iterator_to_array($this->database->query(
            'SELECT * FROM mcp_approval_event WHERE event_type = :type',
            ['type' => 'decided'],
        ));
        self::assertCount(1, $rows);
        self::assertSame('Confirmed with the requester in ticket WSY-88.', $rows[0]['decision_reason']);
        // The decided row carries only the decision, operator and reason as new
        // evidence — never the request payload or raw arguments.
        self::assertNull($rows[0]['safe_arguments']);
        self::assertNull($rows[0]['receipt_id']);
        self::assertStringNotContainsString(self::RAW_SECRET, json_encode($rows[0], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function decide_round_trips_a_unicode_denial_reason(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);

        $denied = $this->store->decide(
            $request->id,
            approved: false,
            operatorUid: 42,
            reason: 'Gaawiin — nishtam gagwejim. ✗',
        );

        self::assertSame(ApprovalStatus::Denied, $denied->status);
        self::assertSame('Gaawiin — nishtam gagwejim. ✗', $denied->decisionReason);
        self::assertSame('Gaawiin — nishtam gagwejim. ✗', $this->store->find($request->id)?->decisionReason);
    }

    #[Test]
    public function decide_normalizes_an_omitted_or_blank_reason_to_null(): void
    {
        $withoutReason = $this->store->open($this->tuple(), 'corr-1', []);
        self::assertNull($this->store->decide($withoutReason->id, approved: true, operatorUid: 42)->decisionReason);

        $blankReason = $this->store->open(
            ApprovalTuple::forCall('token:ab12', 'mcp.write', 'node_update', ['id' => 9]),
            'corr-2',
            [],
        );
        self::assertNull(
            $this->store->decide($blankReason->id, approved: false, operatorUid: 42, reason: "  \t ")->decisionReason,
        );

        $rows = iterator_to_array($this->database->query(
            'SELECT decision_reason FROM mcp_approval_event WHERE event_type = :type',
            ['type' => 'decided'],
        ));
        self::assertSame([['decision_reason' => null], ['decision_reason' => null]], $rows);
    }

    #[Test]
    public function decide_accepts_a_reason_of_exactly_500_unicode_characters(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);
        $boundary = str_repeat('✓', 500);

        $approved = $this->store->decide($request->id, approved: true, operatorUid: 42, reason: $boundary);

        self::assertSame($boundary, $approved->decisionReason);
        self::assertSame($boundary, $this->store->find($request->id)?->decisionReason);
    }

    #[Test]
    public function decide_rejects_an_invalid_reason_before_any_row_is_appended(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);

        foreach ([str_repeat('✓', 501), "line one\nline two", "col one\tcol two", "cr\rhere"] as $reason) {
            try {
                $this->store->decide($request->id, approved: true, operatorUid: 42, reason: $reason);
                self::fail('An invalid decision reason must be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }

        $rows = iterator_to_array($this->database->query(
            'SELECT id FROM mcp_approval_event WHERE event_type = :type',
            ['type' => 'decided'],
        ));
        self::assertSame([], $rows);
        // The request is untouched: still pending and still decidable.
        self::assertSame(ApprovalStatus::Pending, $this->store->find($request->id)?->status);
        self::assertSame(
            ApprovalStatus::Approved,
            $this->store->decide($request->id, approved: true, operatorUid: 42, reason: 'Now valid.')->status,
        );
    }

    #[Test]
    public function a_second_decision_is_rejected_by_the_application_guard(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);
        $this->store->decide($request->id, approved: true, operatorUid: 42);

        $this->expectException(ApprovalAlreadyDecidedException::class);
        $this->store->decide($request->id, approved: true, operatorUid: 43);
    }

    #[Test]
    public function a_second_decision_is_impossible_at_the_storage_layer(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);
        $this->store->decide($request->id, approved: true, operatorUid: 42);

        $this->expectException(\Throwable::class);
        $this->insertEventRow($request->id, 'decided', ['decision' => 'denied', 'operator_uid' => 43]);
    }

    #[Test]
    public function decide_rejects_unknown_requests(): void
    {
        $this->expectException(ApprovalStoreException::class);

        $this->store->decide('apr_' . str_repeat('0', 32), approved: true, operatorUid: 42);
    }

    #[Test]
    public function decide_rejects_expired_requests_even_at_the_exact_boundary(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);
        $this->clock->set($request->expiresAt);

        try {
            $this->store->decide($request->id, approved: true, operatorUid: 42);
            self::fail('An expired request must not be decidable.');
        } catch (ApprovalStoreException $e) {
            self::assertNotInstanceOf(ApprovalAlreadyDecidedException::class, $e);
        }

        self::assertSame(ApprovalStatus::Expired, $this->store->find($request->id)?->status);
    }

    #[Test]
    public function decide_rejects_a_non_positive_operator_uid(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);

        $this->expectException(\InvalidArgumentException::class);
        $this->store->decide($request->id, approved: true, operatorUid: 0);
    }

    #[Test]
    public function an_approved_request_expires_when_left_unconsumed(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);
        $this->store->decide($request->id, approved: true, operatorUid: 42);

        $this->clock->set($request->expiresAt);

        self::assertSame(ApprovalStatus::Expired, $this->store->find($request->id)?->status);
        self::assertFalse($this->store->consume($request->id, 'receipt-1', 'corr-retry'));
    }

    #[Test]
    public function consume_succeeds_once_and_joins_the_receipt_and_retry_correlation(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-original', []);
        $this->store->decide($request->id, approved: true, operatorUid: 42);

        self::assertTrue($this->store->consume($request->id, 'ledger-receipt-1', 'corr-retry'));
        self::assertSame(ApprovalStatus::Consumed, $this->store->find($request->id)?->status);

        $rows = iterator_to_array($this->database->query(
            'SELECT event_type, correlation_id, receipt_id FROM mcp_approval_event WHERE request_id = :id ORDER BY id',
            ['id' => $request->id],
        ));
        self::assertSame('requested', $rows[0]['event_type']);
        self::assertSame('corr-original', $rows[0]['correlation_id']);
        self::assertSame('consumed', $rows[2]['event_type']);
        self::assertSame('corr-retry', $rows[2]['correlation_id']);
        self::assertSame('ledger-receipt-1', $rows[2]['receipt_id']);
    }

    #[Test]
    public function consume_is_once_only(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);
        $this->store->decide($request->id, approved: true, operatorUid: 42);

        self::assertTrue($this->store->consume($request->id, 'receipt-1', 'corr-retry'));
        self::assertFalse($this->store->consume($request->id, 'receipt-2', 'corr-retry-2'));
    }

    #[Test]
    public function a_second_consumption_is_impossible_at_the_storage_layer(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);
        $this->store->decide($request->id, approved: true, operatorUid: 42);
        self::assertTrue($this->store->consume($request->id, 'receipt-1', 'corr-retry'));

        $this->expectException(\Throwable::class);
        $this->insertEventRow($request->id, 'consumed', ['receipt_id' => 'receipt-2']);
    }

    #[Test]
    public function consume_returns_false_when_a_concurrent_consumer_already_won(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);
        $this->store->decide($request->id, approved: true, operatorUid: 42);

        // Simulate the other consumer's committed row landing first.
        $this->insertEventRow($request->id, 'consumed', ['receipt_id' => 'other-receipt']);

        self::assertFalse($this->store->consume($request->id, 'receipt-2', 'corr-retry'));
    }

    #[Test]
    public function consume_refuses_pending_denied_expired_and_unknown_requests(): void
    {
        $pending = $this->store->open($this->tuple(), 'corr-1', []);
        self::assertFalse($this->store->consume($pending->id, 'receipt-1', 'corr-retry'));

        $this->store->decide($pending->id, approved: false, operatorUid: 42);
        self::assertFalse($this->store->consume($pending->id, 'receipt-1', 'corr-retry'));

        self::assertFalse($this->store->consume('apr_' . str_repeat('0', 32), 'receipt-1', 'corr-retry'));

        $expiring = $this->store->open(
            ApprovalTuple::forCall('token:ab12', 'mcp.write', 'node_update', ['id' => 99]),
            'corr-2',
            [],
        );
        $this->store->decide($expiring->id, approved: true, operatorUid: 42);
        $this->clock->set($expiring->expiresAt);
        self::assertFalse($this->store->consume($expiring->id, 'receipt-1', 'corr-retry'));
    }

    #[Test]
    public function consume_has_no_sub_second_expiry_escape_in_either_direction(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);
        $this->store->decide($request->id, approved: true, operatorUid: 42);

        $this->clock->set($request->expiresAt->modify('-1 microsecond'));
        self::assertTrue($this->store->consume($request->id, 'receipt-1', 'corr-retry'));

        $late = $this->store->open(
            ApprovalTuple::forCall('token:ab12', 'mcp.write', 'node_update', ['id' => 99]),
            'corr-2',
            [],
        );
        $this->store->decide($late->id, approved: true, operatorUid: 42);
        $this->clock->set($late->expiresAt);
        self::assertFalse($this->store->consume($late->id, 'receipt-2', 'corr-retry-2'));
    }

    #[Test]
    public function the_event_table_is_append_only_through_the_audit_database(): void
    {
        $request = $this->store->open($this->tuple(), 'corr-1', []);
        $guarded = new AppendOnlyAuditDatabase($this->database);

        try {
            $guarded->update('mcp_approval_event');
            self::fail('UPDATE on mcp_approval_event must be refused.');
        } catch (\LogicException) {
        }

        try {
            $guarded->delete('mcp_approval_event');
            self::fail('DELETE on mcp_approval_event must be refused.');
        } catch (\LogicException) {
        }

        try {
            iterator_to_array($guarded->query('DELETE FROM "mcp_approval_event" WHERE request_id = :id', ['id' => $request->id]));
            self::fail('Raw DELETE on mcp_approval_event must be refused.');
        } catch (\LogicException) {
        }

        self::assertNotNull($this->store->find($request->id));
    }

    #[Test]
    public function store_failures_surface_as_sanitized_typed_exceptions(): void
    {
        $broken = new DatabaseOperationApprovalStore(DBALDatabase::createSqlite(), $this->clock);

        try {
            $broken->open($this->tuple(), 'corr-1', []);
            self::fail('A store without its schema must fail closed.');
        } catch (ApprovalStoreException $e) {
            self::assertNotNull($e->getPrevious());
            self::assertStringNotContainsString('SQLSTATE', $e->getMessage());
            self::assertStringNotContainsString('no such table', $e->getMessage());
            self::assertStringNotContainsString('mcp_approval_event', $e->getMessage());
        }
    }

    private function tuple(): ApprovalTuple
    {
        return ApprovalTuple::forCall('token:ab12', 'mcp.write', 'node_update', [
            'id' => 7,
            'title' => 'A',
            'password' => self::RAW_SECRET,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function insertEventRow(string $requestId, string $eventType, array $extra): void
    {
        $tuple = $this->tuple();
        $this->database->insert('mcp_approval_event')->values($extra + [
            'request_id' => $requestId,
            'event_type' => $eventType,
            'request_key' => $tuple->requestKey,
            'principal_key' => $tuple->principalKey,
            'surface' => $tuple->surface,
            'operation' => $tuple->operation,
            'arguments_fingerprint' => $tuple->argumentsFingerprint,
            'correlation_id' => 'corr-manual',
            'created_at' => self::START,
        ])->execute();
    }
}
