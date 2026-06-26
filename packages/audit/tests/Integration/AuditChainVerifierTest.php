<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Entity\AuditCheckpoint;
use Waaseyaa\Audit\Integrity\AuditChainVerifier;
use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Audit\Integrity\CheckpointSink;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Database\DBALDatabase;

/**
 * Integration proof for WP3: AuditChainVerifier detects intact chains and
 * various tamper/corruption scenarios.
 *
 * All tests use an in-memory SQLite instance via DBALDatabase::createSqlite().
 */
#[CoversClass(AuditChainVerifier::class)]
final class AuditChainVerifierTest extends TestCase
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

    private function insertEvent(string $uuid, string $kind = 'entity.write', int $accountUid = 1): void
    {
        $this->db->insert('audit_event')->values([
            'uuid'           => $uuid,
            'event_kind'     => $kind,
            'account_uid'    => $accountUid,
            'actor_uid'      => $accountUid,
            'entity_type_id' => 'node',
            'entity_uuid'    => 'eeeeeeee-0000-0000-0000-000000000001',
            'subject_uri'    => '/entities/node/test',
            'outcome'        => 'allowed',
            'severity'       => 'info',
            'attributes'     => '{}',
            'created_at'     => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
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

    // ------------------------------------------------------------------
    // Case 1: intact chain
    // ------------------------------------------------------------------

    #[Test]
    public function intact_chain_returns_ok_with_correct_counts(): void
    {
        $this->insertEvent('u-1');
        $this->insertEvent('u-2');
        $this->insertEvent('u-3');
        $this->seal();

        $result = $this->verifier()->verify();

        self::assertTrue($result->ok, 'intact chain must return ok');
        self::assertGreaterThanOrEqual(1, $result->segmentsVerified, 'at least one segment verified');
        self::assertSame(3, $result->rowsVerified, 'three sealed rows verified');
        self::assertNull($result->failureKind);
        self::assertNull($result->firstBrokenId);
    }

    #[Test]
    public function intact_chain_with_multiple_segments_returns_ok(): void
    {
        $this->insertEvent('u-a');
        $this->insertEvent('u-b');
        $this->seal();

        $this->insertEvent('u-c');
        $this->insertEvent('u-d');
        $this->seal();

        $result = $this->verifier()->verify();

        self::assertTrue($result->ok);
        self::assertSame(2, $result->segmentsVerified);
        self::assertSame(4, $result->rowsVerified);
    }

    #[Test]
    public function pending_unsealed_rows_are_counted(): void
    {
        $this->insertEvent('u-x');
        $this->seal();

        // Insert one more event without sealing.
        $this->insertEvent('u-y');

        $result = $this->verifier()->verify();

        self::assertTrue($result->ok);
        self::assertSame(1, $result->pendingUnsealedRows);
    }

    // ------------------------------------------------------------------
    // Case 2: tamper-detection (content edit)
    // ------------------------------------------------------------------

    #[Test]
    public function content_tamper_on_sealed_row_is_detected(): void
    {
        $this->insertEvent('u-1');
        $this->insertEvent('u-2');
        $this->insertEvent('u-3');
        $this->seal();

        // Tamper: mutate the content column of row 2 directly.
        $this->db->getConnection()->executeStatement(
            "UPDATE audit_event SET subject_uri = '/tampered' WHERE id = 2",
        );

        $result = $this->verifier()->verify();

        self::assertFalse($result->ok, 'tampered content must be detected');
        self::assertSame('row_content', $result->failureKind);
        self::assertSame(2, $result->firstBrokenId);
    }

    // ------------------------------------------------------------------
    // Case 3: gap-detection (row deleted)
    // ------------------------------------------------------------------

    #[Test]
    public function deleted_sealed_row_is_detected(): void
    {
        $this->insertEvent('u-1');
        $this->insertEvent('u-2');
        $this->insertEvent('u-3');
        $this->seal();

        // Delete the middle row.
        $this->db->getConnection()->executeStatement(
            'DELETE FROM audit_event WHERE id = 2',
        );

        $result = $this->verifier()->verify();

        self::assertFalse($result->ok, 'missing row must be detected');
        self::assertContains($result->failureKind, ['row_count', 'chain_link'], 'failure kind must reflect missing row');
    }

    // ------------------------------------------------------------------
    // Case 4: checkpoint-forgery
    // ------------------------------------------------------------------

    #[Test]
    public function tampered_checkpoint_segment_hash_is_detected(): void
    {
        $this->insertEvent('u-1');
        $this->insertEvent('u-2');
        $this->seal();

        // Mutate segment_hash in the (non-genesis) checkpoint.
        $this->db->getConnection()->executeStatement(
            "UPDATE audit_checkpoint SET segment_hash = '" . str_repeat('f', 64) . "' WHERE is_genesis = 0",
        );

        $result = $this->verifier()->verify();

        self::assertFalse($result->ok, 'tampered segment_hash must be detected');
        self::assertContains(
            $result->failureKind,
            ['segment_hash', 'checkpoint_hash', 'checkpoint_chain'],
            'failure kind must reflect forged checkpoint',
        );
    }

    // ------------------------------------------------------------------
    // Case 5: stored row_hash tampered
    // ------------------------------------------------------------------

    #[Test]
    public function tampered_stored_row_hash_is_detected(): void
    {
        $this->insertEvent('u-1');
        $this->insertEvent('u-2');
        $this->seal();

        // Change row 2's row_hash to a wrong value (but leave content intact).
        $this->db->getConnection()->executeStatement(
            "UPDATE audit_event SET row_hash = '" . str_repeat('a', 64) . "' WHERE id = 2",
        );

        $result = $this->verifier()->verify();

        self::assertFalse($result->ok, 'tampered row_hash must be detected');
        self::assertContains(
            $result->failureKind,
            ['chain_link', 'row_content'],
            'failure kind must reflect tampered hash',
        );
    }
}
