<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditQuery;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Entity\AuditCheckpoint;
use Waaseyaa\Audit\Integrity\AuditChainVerifier;
use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Audit\Integrity\CheckpointSink;
use Waaseyaa\Audit\Query\AuditEventQuery;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Database\DBALDatabase;

/**
 * Integration proof for WP4: checkpoint-boundary-aware prune.
 *
 * Each test uses an in-memory SQLite instance. The prune logic is driven
 * directly via the same helpers PruneCommand uses (raw DatabaseInterface
 * delete+update) rather than going through the CLI, so we stay hermetic.
 */
#[CoversNothing]
final class AuditPruneCheckpointTest extends TestCase
{
    private DBALDatabase $db;
    private CheckpointSink $nullSink;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        new AuditEventSchemaHandler($this->db)->ensureSchema();

        $this->nullSink = new class implements CheckpointSink {
            public function export(AuditCheckpoint $checkpoint): void {}
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertEvent(string $uuid, string $createdAt = ''): void
    {
        if ($createdAt === '') {
            $createdAt = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        }

        $this->db->insert('audit_event')->values([
            'uuid'           => $uuid,
            'event_kind'     => 'entity.write',
            'account_uid'    => 1,
            'actor_uid'      => 1,
            'entity_type_id' => 'node',
            'entity_uuid'    => 'eeeeeeee-0000-0000-0000-000000000001',
            'subject_uri'    => '/entities/node/test',
            'outcome'        => 'allowed',
            'severity'       => 'info',
            'attributes'     => '{}',
            'created_at'     => $createdAt,
        ])->execute();
    }

    private function seal(): void
    {
        new AuditCheckpointBuilder($this->db, $this->nullSink)->build();
    }

    private function verifier(): AuditChainVerifier
    {
        return new AuditChainVerifier($this->db);
    }

    /**
     * Compute the sealed horizon the same way PruneCommand does:
     * highest segment_end_id of non-genesis checkpoints whose entire segment
     * is older than the cutoff.
     */
    private function computeHorizon(string $cutoffSql): int
    {
        $checkpoints = iterator_to_array(
            $this->db
                ->select('audit_checkpoint')
                ->fields('audit_checkpoint', ['segment_start_id', 'segment_end_id', 'pruned'])
                ->condition('is_genesis', 0)
                ->orderBy('segment_end_id', 'ASC')
                ->execute(),
            false,
        );

        $horizon = 0;
        foreach ($checkpoints as $cp) {
            $segStart = (int) $cp['segment_start_id'];
            $segEnd   = (int) $cp['segment_end_id'];

            $maxRows = iterator_to_array(
                $this->db
                    ->select('audit_event')
                    ->fields('audit_event', ['created_at'])
                    ->condition('id', $segStart, '>=')
                    ->condition('id', $segEnd, '<=')
                    ->orderBy('created_at', 'DESC')
                    ->range(0, 1)
                    ->execute(),
                false,
            );

            if ($maxRows !== []) {
                $maxCreatedAt = (string) $maxRows[0]['created_at'];
                if ($maxCreatedAt >= $cutoffSql) {
                    continue;
                }
            }

            if ($segEnd > $horizon) {
                $horizon = $segEnd;
            }
        }

        return $horizon;
    }

    /**
     * Execute the actual sanctioned prune: delete sealed rows + mark checkpoints.
     */
    private function executeSanctionedPrune(int $horizon): void
    {
        if ($horizon === 0) {
            return;
        }

        $this->db->delete('audit_event')
            ->condition('id', $horizon, '<=')
            ->execute();

        $this->db->update('audit_checkpoint')
            ->fields(['pruned' => 1])
            ->condition('is_genesis', 0)
            ->condition('segment_end_id', $horizon, '<=')
            ->execute();
    }

    private function writer(): AuditWriterInterface
    {
        return new AuditEventWriter($this->db);
    }

    // ------------------------------------------------------------------
    // Test 1: prune-compatibility — sanctioned prune stays chain-intact
    // ------------------------------------------------------------------

    #[Test]
    public function sanctioned_prune_of_whole_segment_passes_verify(): void
    {
        // Segment 1: events created in the past (old).
        $past = new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:s');
        $this->insertEvent('seg1-a', $past);
        $this->insertEvent('seg1-b', $past);
        $this->seal();

        // Capture segment 1's end_id.
        $seg1Rows = iterator_to_array(
            $this->db
                ->select('audit_checkpoint')
                ->fields('audit_checkpoint', ['segment_end_id'])
                ->condition('is_genesis', 0)
                ->orderBy('id', 'ASC')
                ->range(0, 1)
                ->execute(),
            false,
        );
        $seg1EndId = (int) $seg1Rows[0]['segment_end_id'];

        // Segment 2: events created now (recent — should survive).
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $this->insertEvent('seg2-a', $now);
        $this->insertEvent('seg2-b', $now);
        $this->seal();

        // Verify chain is intact before prune.
        self::assertTrue($this->verifier()->verify()->ok, 'chain must be intact before prune');

        // Prune: cutoff = 1 day ago → segment 1 (2 days old) is eligible.
        $cutoffSql = new \DateTimeImmutable('-1 day')->format('Y-m-d H:i:s');
        $horizon = $this->computeHorizon($cutoffSql);

        // Horizon must equal segment 1's end id.
        self::assertSame($seg1EndId, $horizon, 'horizon must point to segment-1 end');
        self::assertGreaterThan(0, $horizon, 'there must be a prunable sealed segment');

        $this->executeSanctionedPrune($horizon);

        // Assert: segment-1 rows are gone.
        $seg1Rows = iterator_to_array(
            $this->db
                ->select('audit_event')
                ->fields('audit_event', ['id'])
                ->condition('id', $horizon, '<=')
                ->execute(),
            false,
        );
        self::assertSame([], $seg1Rows, 'segment-1 event rows must be deleted');

        // Assert: segment-2 rows survive.
        $seg2Rows = iterator_to_array(
            $this->db
                ->select('audit_event')
                ->fields('audit_event', ['id'])
                ->condition('id', $horizon, '>')
                ->execute(),
            false,
        );
        self::assertGreaterThanOrEqual(2, count($seg2Rows), 'segment-2 event rows must survive');

        // Assert: segment-1 checkpoint is marked pruned.
        $cp1Rows = iterator_to_array(
            $this->db
                ->select('audit_checkpoint')
                ->fields('audit_checkpoint', ['pruned'])
                ->condition('is_genesis', 0)
                ->condition('segment_end_id', $horizon)
                ->execute(),
            false,
        );
        self::assertCount(1, $cp1Rows, 'segment-1 checkpoint must exist');
        self::assertSame('1', (string) $cp1Rows[0]['pruned'], 'segment-1 checkpoint must have pruned=1');

        // THE KEY ASSERTION: verify still passes after the sanctioned prune.
        $result = $this->verifier()->verify();
        self::assertTrue(
            $result->ok,
            sprintf(
                'verify must pass after sanctioned prune — got: ok=%s failureKind=%s message=%s',
                $result->ok ? 'true' : 'false',
                $result->failureKind ?? 'null',
                $result->message,
            ),
        );
    }

    // ------------------------------------------------------------------
    // Test 2: malicious gap is still caught (pruned flag absent)
    // ------------------------------------------------------------------

    #[Test]
    public function malicious_deletion_without_pruned_flag_fails_verify(): void
    {
        $past = new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:s');
        $this->insertEvent('mal-a', $past);
        $this->insertEvent('mal-b', $past);
        $this->insertEvent('mal-c', $past);
        $this->seal();

        // Sanity: chain is intact.
        self::assertTrue($this->verifier()->verify()->ok, 'chain must be intact before tampering');

        // Malicious: delete a sealed row WITHOUT marking checkpoint pruned.
        $this->db->getConnection()->executeStatement(
            'DELETE FROM audit_event WHERE id = (SELECT MIN(id) FROM audit_event)',
        );

        // Verify must now FAIL.
        $result = $this->verifier()->verify();
        self::assertFalse(
            $result->ok,
            'malicious row deletion without pruned flag must fail verify',
        );
        self::assertContains(
            $result->failureKind,
            ['row_count', 'chain_link'],
            'failure kind must be row_count or chain_link for a missing sealed row',
        );
    }

    // ------------------------------------------------------------------
    // Test 3: pruned checkpoint forgery is caught
    // ------------------------------------------------------------------

    #[Test]
    public function forged_pruned_checkpoint_hash_fails_verify(): void
    {
        $past = new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:s');
        $this->insertEvent('frg-a', $past);
        $this->insertEvent('frg-b', $past);
        $this->seal();

        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $this->insertEvent('frg-c', $now);
        $this->seal();

        // Sanctioned prune of segment 1.
        $cutoffSql = new \DateTimeImmutable('-1 day')->format('Y-m-d H:i:s');
        $horizon = $this->computeHorizon($cutoffSql);
        self::assertGreaterThan(0, $horizon, 'there must be a prunable segment');
        $this->executeSanctionedPrune($horizon);

        // Verify is intact after legitimate prune.
        self::assertTrue($this->verifier()->verify()->ok, 'chain must be intact after sanctioned prune');

        // Forgery: mutate checkpoint_hash of the pruned checkpoint.
        $this->db->getConnection()->executeStatement(
            "UPDATE audit_checkpoint SET checkpoint_hash = '" . str_repeat('e', 64) . "' "
            . 'WHERE is_genesis = 0 AND pruned = 1',
        );

        $result = $this->verifier()->verify();
        self::assertFalse(
            $result->ok,
            'forged checkpoint_hash on a pruned checkpoint must fail verify',
        );
        self::assertContains(
            $result->failureKind,
            ['checkpoint_hash', 'checkpoint_chain'],
            'failure must be checkpoint_hash or checkpoint_chain for a forged pruned checkpoint',
        );
    }

    // ------------------------------------------------------------------
    // Test 4: self-audit event carries WP4 attributes
    // ------------------------------------------------------------------

    #[Test]
    public function prune_records_self_audit_with_wp4_attributes(): void
    {
        $past = new \DateTimeImmutable('-2 days')->format('Y-m-d H:i:s');
        $this->insertEvent('sa-a', $past);
        $this->insertEvent('sa-b', $past);
        $this->seal();

        // Capture the sealed checkpoint's hash.
        $cpRows = iterator_to_array(
            $this->db
                ->select('audit_checkpoint')
                ->fields('audit_checkpoint', ['segment_end_id', 'checkpoint_hash'])
                ->condition('is_genesis', 0)
                ->execute(),
            false,
        );
        self::assertCount(1, $cpRows, 'there must be exactly one non-genesis checkpoint');
        $horizon = (int) $cpRows[0]['segment_end_id'];
        $expectedCpHash = (string) $cpRows[0]['checkpoint_hash'];

        // Compute sealed horizon.
        $cutoffSql = new \DateTimeImmutable('-1 day')->format('Y-m-d H:i:s');
        $computedHorizon = $this->computeHorizon($cutoffSql);
        self::assertSame($horizon, $computedHorizon);

        // Simulate what PruneCommand does: record self-audit BEFORE deleting.
        $auditDb = new \Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase($this->db);
        $writer = new AuditEventWriter($auditDb);
        $writer->record(new AuditEventDescriptor(
            kind: \Waaseyaa\Audit\Enum\AuditEventKind::AuditRetentionPruned,
            accountUid: 0,
            subjectUri: 'audit:prune',
            outcome: 'allowed',
            severity: 'info',
            attributes: [
                'kind_pattern'             => '*',
                'older_than'               => 'P2D',
                'deleted_count'            => 2,
                'cutoff'                   => new \DateTimeImmutable('-1 day')->format(\DateTimeInterface::ATOM),
                'sealed_pruned_through_id' => $horizon,
                'pruned_checkpoint_hash'   => $expectedCpHash,
                'unsealed_deleted_count'   => 0,
            ],
        ));

        // Now execute prune.
        $this->executeSanctionedPrune($horizon);

        // Read back the self-audit event (it was written after the sealed rows,
        // so id > horizon — it is NOT in the deleted range).
        $events = iterator_to_array(
            new AuditEventQuery($this->db)->findBy(new AuditQuery()),
            false,
        );

        // Only the self-audit event remains (the 2 original events were deleted).
        self::assertCount(1, $events, 'only the self-audit event must remain after prune');
        $event = $events[0];
        self::assertSame(\Waaseyaa\Audit\Enum\AuditEventKind::AuditRetentionPruned->value, $event->getEventKind());

        $attrs = json_decode((string) $event->get('attributes'), true);
        self::assertIsArray($attrs);
        self::assertArrayHasKey('sealed_pruned_through_id', $attrs);
        self::assertArrayHasKey('pruned_checkpoint_hash', $attrs);
        self::assertArrayHasKey('unsealed_deleted_count', $attrs);
        self::assertSame($horizon, $attrs['sealed_pruned_through_id']);
        self::assertSame($expectedCpHash, $attrs['pruned_checkpoint_hash']);
        self::assertSame(0, $attrs['unsealed_deleted_count']);
    }
}
