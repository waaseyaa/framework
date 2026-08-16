<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Entity\AuditCheckpoint;
use Waaseyaa\Audit\Integrity\AuditChainVerifier;
use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Audit\Integrity\AuditCheckpointHasher;
use Waaseyaa\Audit\Integrity\AuditPruneAuthorization;
use Waaseyaa\Audit\Integrity\CheckpointSink;
use Waaseyaa\Audit\Integrity\LegacyCheckpointSignatureMigrator;
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
        RuntimeSchemaMigrations::audit($this->db);

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

    private function seal(?string $hmacKey = null): void
    {
        new AuditCheckpointBuilder($this->db, $this->nullSink, hmacKey: $hmacKey)->build();
    }

    private function verifier(?string $hmacKey = null): AuditChainVerifier
    {
        return new AuditChainVerifier($this->db, hmacKey: $hmacKey);
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

    #[Test]
    public function hkdf_checkpoint_signatures_are_versioned_and_verified(): void
    {
        $key = random_bytes(32);
        new LegacyCheckpointSignatureMigrator($this->db, $key)->migrate();
        $this->insertEvent('signed-1');
        $this->seal($key);

        $signature = (string) $this->db->getConnection()->fetchOne(
            'SELECT signature FROM audit_checkpoint WHERE is_genesis = 0',
        );
        self::assertFalse(str_contains($signature, $key), 'Stored signatures must not contain raw key bytes.');
        self::assertFalse(
            str_contains($signature, base64_encode($key)),
            'Stored signatures must not contain encoded key bytes.',
        );
        self::assertTrue(
            preg_match('/^hmac-sha256\\.hkdf-v1:[0-9a-f]{64}$/', $signature) === 1,
            'Stored signatures must use the versioned HKDF-v1 envelope.',
        );
        self::assertTrue($this->verifier($key)->verify()->ok);

        $this->db->getConnection()->executeStatement(
            "UPDATE audit_checkpoint SET signature = 'hmac-sha256.hkdf-v1:" . str_repeat('0', 64) . "' WHERE is_genesis = 0",
        );

        $result = $this->verifier($key)->verify();
        self::assertFalse($result->ok);
        self::assertSame('checkpoint_signature', $result->failureKind);
    }

    #[Test]
    public function first_keyed_checkpoint_authenticates_the_pristine_genesis_anchor(): void
    {
        $key = random_bytes(32);
        $this->insertEvent('fresh-keyed-install');

        $this->seal($key);

        self::assertTrue(
            $this->verifier($key)->verify()->ok,
            'The first keyed checkpoint must not leave the pristine genesis anchor unauthenticated.',
        );
        self::assertSame(
            0,
            new LegacyCheckpointSignatureMigrator($this->db, $key)->migrate(),
            'A fresh keyed chain must not require an operator migration after its first checkpoint.',
        );
    }

    #[Test]
    public function keyed_builder_authenticates_pristine_genesis_even_without_events(): void
    {
        $key = random_bytes(32);

        $this->seal($key);

        $result = $this->verifier($key)->verify();
        self::assertTrue($result->ok);
        self::assertSame(0, $result->segmentsVerified);
        self::assertSame(0, new LegacyCheckpointSignatureMigrator($this->db, $key)->migrate());
    }

    #[Test]
    public function keyed_builder_refuses_a_genesis_anchor_authenticated_by_another_key(): void
    {
        $firstKey = random_bytes(32);
        $secondKey = random_bytes(32);
        new LegacyCheckpointSignatureMigrator($this->db, $firstKey)->migrate();
        $this->insertEvent('conflicting-genesis-key');

        try {
            $this->seal($secondKey);
            self::fail('A conflicting genesis key must prevent checkpoint creation.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('refused', $e->getMessage());
        }

        self::assertSame(
            0,
            (int) $this->db->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_checkpoint WHERE is_genesis = 0'),
        );
        self::assertSame(
            '',
            (string) $this->db->getConnection()->fetchOne('SELECT row_hash FROM audit_event WHERE uuid = ?', ['conflicting-genesis-key']),
        );
    }

    #[Test]
    public function audit_key_holders_never_expose_derived_bytes_through_debug_or_serialization(): void
    {
        $key = random_bytes(32);
        $builder = new AuditCheckpointBuilder($this->db, $this->nullSink, hmacKey: $key);
        $verifier = new AuditChainVerifier($this->db, hmacKey: $key);
        $migrator = new LegacyCheckpointSignatureMigrator($this->db, $key);

        foreach ([$builder, $verifier, $migrator] as $holder) {
            ob_start();
            var_dump($holder);
            $debug = (string) ob_get_clean() . var_export($holder, true);
            self::assertFalse(str_contains($debug, $key), 'Audit debug output must not contain derived key bytes.');

            try {
                serialize($holder);
                self::fail('Audit key holders must not be serializable.');
            } catch (\Throwable $e) {
                self::assertFalse(str_contains($e->getMessage(), $key), 'Serialization errors must not contain derived key bytes.');
            }
        }
    }

    #[Test]
    public function legacy_signatures_are_accepted_only_before_authenticated_suffix(): void
    {
        $key = random_bytes(32);
        $this->insertEvent('legacy');
        $this->seal();
        $this->db->getConnection()->executeStatement(
            'UPDATE audit_checkpoint SET signature = ? WHERE segment_end_id = 1',
            [str_repeat('b', 64)],
        );
        $this->insertEvent('authenticated');
        $this->seal($key);
        $this->insertEvent('downgrade');
        $this->seal();

        $result = $this->verifier($key)->verify();

        self::assertFalse($result->ok);
        self::assertSame('checkpoint_signature', $result->failureKind);
    }

    /** @return iterable<string, array{string}> */
    public static function downgradedSignatures(): iterable
    {
        yield 'legacy bare hex' => [str_repeat('a', 64)];
        yield 'malformed' => ['not-a-signature'];
    }

    #[Test]
    #[DataProvider('downgradedSignatures')]
    public function bare_or_malformed_signature_after_authenticated_suffix_fails(string $downgrade): void
    {
        $key = random_bytes(32);
        $this->insertEvent('authenticated');
        $this->seal($key);
        $this->insertEvent('downgraded');
        $this->seal($key);
        $this->db->getConnection()->executeStatement(
            'UPDATE audit_checkpoint SET signature = ? WHERE segment_end_id = 2',
            [$downgrade],
        );

        $result = $this->verifier($key)->verify();

        self::assertFalse($result->ok);
        self::assertSame('checkpoint_signature', $result->failureKind);
    }

    #[Test]
    public function stripping_every_version_prefix_cannot_downgrade_authenticated_history_to_legacy(): void
    {
        $key = random_bytes(32);
        $this->insertEvent('authenticated-1');
        $this->seal($key);
        $this->insertEvent('authenticated-2');
        $this->seal($key);

        $this->db->getConnection()->executeStatement(
            "UPDATE audit_checkpoint SET signature = REPLACE(signature, 'hmac-sha256.hkdf-v1:', '') WHERE is_genesis = 0",
        );

        $result = $this->verifier($key)->verify();

        self::assertFalse($result->ok);
        self::assertSame('checkpoint_signature', $result->failureKind);
    }

    #[Test]
    public function configured_key_requires_authenticated_verification_when_checkpoint_fields_change(): void
    {
        $key = random_bytes(32);
        RuntimeSchemaMigrations::audit($this->db);
        $this->insertEvent('authenticated-checkpoint');
        $this->seal($key);

        $genesisHash = (string) $this->db->getConnection()->fetchOne(
            'SELECT checkpoint_hash FROM audit_checkpoint WHERE is_genesis = 1',
        );
        $replacementSegmentHash = hash('sha256', 'replacement-segment');
        $replacementCheckpointHash = AuditCheckpointHasher::checkpointHash(
            1,
            1,
            1,
            $replacementSegmentHash,
            $genesisHash,
        );

        $this->db->getConnection()->executeStatement(
            "UPDATE audit_checkpoint SET signature = REPLACE(signature, 'hmac-sha256.hkdf-v1:', '') WHERE is_genesis = 1",
        );
        $this->db->getConnection()->executeStatement(
            'UPDATE audit_checkpoint SET pruned = 1, segment_hash = ?, checkpoint_hash = ?, signature = ? WHERE is_genesis = 0',
            [$replacementSegmentHash, $replacementCheckpointHash, hash_hmac('sha256', $replacementCheckpointHash, $key)],
        );

        $result = $this->verifier($key)->verify();

        self::assertFalse($result->ok);
        self::assertSame('checkpoint_signature', $result->failureKind);
    }

    #[Test]
    public function explicit_migration_resigns_an_intact_legacy_chain_before_strict_verification(): void
    {
        $key = random_bytes(32);
        $this->insertEvent('legacy-1');
        $this->seal();
        $this->insertEvent('legacy-2');
        $this->seal();

        self::assertFalse($this->verifier($key)->verify()->ok, 'Keyed verification must reject legacy signatures.');

        $migrated = new LegacyCheckpointSignatureMigrator($this->db, $key)->migrate();

        self::assertSame(3, $migrated, 'Genesis and both legacy checkpoints must be re-signed.');
        self::assertTrue($this->verifier($key)->verify()->ok);
        $signatures = $this->db->getConnection()->fetchFirstColumn('SELECT signature FROM audit_checkpoint');
        foreach ($signatures as $signature) {
            self::assertMatchesRegularExpression('/^hmac-sha256\.hkdf-v1:[0-9a-f]{64}$/D', (string) $signature);
        }
    }

    #[Test]
    public function explicit_migration_authorizes_trusted_pruned_legacy_checkpoints(): void
    {
        $key = random_bytes(32);
        $this->insertEvent('trusted-legacy-prune');
        $this->seal();
        $this->db->getConnection()->executeStatement('DELETE FROM audit_event');
        $this->db->getConnection()->executeStatement(
            'UPDATE audit_checkpoint SET pruned = 1 WHERE is_genesis = 0',
        );

        $migrated = new LegacyCheckpointSignatureMigrator($this->db, $key)->migrate();

        self::assertSame(2, $migrated);
        self::assertTrue($this->verifier($key)->verify()->ok);
        self::assertMatchesRegularExpression(
            '/^hmac-sha256\.audit-prune\.hkdf-v1:[a-f0-9]{64}$/D',
            (string) $this->db->getConnection()->fetchOne(
                'SELECT prune_authorization FROM audit_checkpoint WHERE is_genesis = 0',
            ),
        );
    }

    #[Test]
    public function explicit_migration_repairs_trusted_pruned_versioned_history(): void
    {
        $key = random_bytes(32);
        new LegacyCheckpointSignatureMigrator($this->db, $key)->migrate();
        $this->insertEvent('trusted-versioned-prune');
        $this->seal($key);
        $this->db->getConnection()->executeStatement('DELETE FROM audit_event');
        $this->db->getConnection()->executeStatement(
            'UPDATE audit_checkpoint SET pruned = 1 WHERE is_genesis = 0',
        );
        self::assertFalse($this->verifier($key)->verify()->ok);

        $migrated = new LegacyCheckpointSignatureMigrator($this->db, $key)->migrate();

        self::assertSame(0, $migrated);
        self::assertTrue($this->verifier($key)->verify()->ok);
    }

    #[Test]
    public function explicit_migration_refuses_tampered_legacy_history_without_writing_signatures(): void
    {
        $key = random_bytes(32);
        $this->insertEvent('legacy-tampered');
        $this->seal();
        $before = $this->db->getConnection()->fetchFirstColumn('SELECT signature FROM audit_checkpoint ORDER BY id');
        $this->db->getConnection()->executeStatement("UPDATE audit_event SET subject_uri = '/tampered' WHERE id = 1");

        try {
            new LegacyCheckpointSignatureMigrator($this->db, $key)->migrate();
            self::fail('Tampered legacy history must not be blessed by migration.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('refused', $e->getMessage());
        }

        self::assertSame($before, $this->db->getConnection()->fetchFirstColumn('SELECT signature FROM audit_checkpoint ORDER BY id'));
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

    #[Test]
    public function keyed_verifier_rejects_a_forged_pruned_flag_without_authenticated_authorization(): void
    {
        $key = random_bytes(32);
        new LegacyCheckpointSignatureMigrator($this->db, $key)->migrate();
        $this->insertEvent('forged-prune-1');
        $this->insertEvent('forged-prune-2');
        $this->seal($key);

        $this->db->getConnection()->executeStatement('DELETE FROM audit_event WHERE id <= 2');
        $this->db->getConnection()->executeStatement(
            'UPDATE audit_checkpoint SET pruned = 1 WHERE is_genesis = 0',
        );

        $result = $this->verifier($key)->verify();

        self::assertFalse($result->ok, 'A mutable pruned flag must not authenticate deletion of a signed segment.');
        self::assertSame('prune_authorization', $result->failureKind);
    }

    #[Test]
    public function prune_authorization_cannot_be_replayed_onto_another_checkpoint(): void
    {
        $key = random_bytes(32);
        new LegacyCheckpointSignatureMigrator($this->db, $key)->migrate();
        $this->insertEvent('prune-replay-1');
        $this->seal($key);
        $this->insertEvent('prune-replay-2');
        $this->seal($key);

        $checkpoints = $this->db->getConnection()->fetchAllAssociative(
            'SELECT id, segment_end_id, checkpoint_hash FROM audit_checkpoint WHERE is_genesis = 0 ORDER BY id',
        );
        $authorization = AuditPruneAuthorization::sign((string) $checkpoints[0]['checkpoint_hash'], $key);
        $this->db->getConnection()->executeStatement('DELETE FROM audit_event');
        $this->db->getConnection()->executeStatement(
            'UPDATE audit_checkpoint SET pruned = 1, prune_authorization = ? WHERE is_genesis = 0',
            [$authorization],
        );

        $result = $this->verifier($key)->verify();

        self::assertFalse($result->ok);
        self::assertSame('prune_authorization', $result->failureKind);
        self::assertSame((int) $checkpoints[1]['segment_end_id'], $result->firstBrokenId);
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
