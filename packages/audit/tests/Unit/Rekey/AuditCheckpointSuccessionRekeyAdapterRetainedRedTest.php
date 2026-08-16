<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Rekey;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Entity\AuditCheckpoint;
use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Audit\Integrity\AuditCheckpointCustody;
use Waaseyaa\Audit\Integrity\AuditChainVerifier;
use Waaseyaa\Audit\Integrity\CheckpointSink;
use Waaseyaa\Audit\Rekey\AuditCheckpointSuccessionRekeyAdapter;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyCoordinator;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyRecord;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyRequest;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyState;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyStore;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/** Retained-red proof for transactional audit checkpoint succession. */
final class AuditCheckpointSuccessionRekeyAdapterRetainedRedTest extends TestCase
{
    private DBALDatabase $database;
    private CheckpointSink $sink;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::audit($this->database);
        $this->sink = new class implements CheckpointSink {
            public function export(AuditCheckpoint $checkpoint): void {}
        };
    }

    #[Test]
    public function forward_transition_appends_one_successor_authenticated_anchor_and_verifies_it(): void
    {
        $this->sealUnderVersion(1, 'anchor-event');
        $context = $this->context($this->database);
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);
        $snapshot = $adapter->snapshot($context);
        $result = $adapter->transitionBatch($context, $snapshot, null, 1);
        $verification = $adapter->verify($context, $snapshot);
        $row = $this->database->getConnection()->fetchAssociative(
            'SELECT * FROM audit_checkpoint_succession WHERE record_kind = ?',
            ['succession-anchor'],
        );

        self::assertSame(1, $snapshot->totalRecords);
        self::assertSame(1, $result->transitionedRecords);
        self::assertSame([ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC => 1], $result->purposeCountDeltas);
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['from_master_version']);
        self::assertSame(2, (int) $row['to_master_version']);
        self::assertStringStartsWith(
            AuditCheckpointCustody::SUCCESSION_PREFIX . '2:',
            (string) $row['signature'],
        );
        self::assertSame(1, $verification[ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC]->verifiedRecords);
    }

    #[Test]
    public function a_checkpoint_appended_after_snapshot_causes_a_typed_conflict(): void
    {
        $this->sealUnderVersion(1, 'before-snapshot');
        $context = $this->context($this->database);
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);
        $snapshot = $adapter->snapshot($context);
        $this->sealUnderVersion(1, 'concurrent-checkpoint');

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        $this->expectExceptionMessage('snapshot');
        $adapter->transitionBatch($context, $snapshot, null, 1);
    }

    #[Test]
    public function an_existing_predecessor_authorized_prune_is_anchored_as_append_only_child_evidence(): void
    {
        $checkpoint = $this->sealUnderVersion(1, 'pruned-at-cutover');
        $custody = new AuditCheckpointCustody($this->keyring(1));
        $this->database->update('audit_checkpoint')->fields([
            'pruned' => 1,
            'prune_authorization' => $custody->sealPruneAuthorization($checkpoint->getCheckpointHash()),
        ])->condition('uuid', $checkpoint->getUuid())->execute();
        $this->database->delete('audit_event')
            ->condition('id', $checkpoint->getSegmentStartId(), '>=')
            ->condition('id', $checkpoint->getSegmentEndId(), '<=')
            ->execute();

        $context = $this->context($this->database);
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);
        $snapshot = $adapter->snapshot($context);
        $adapter->transitionBatch($context, $snapshot, null, 1);
        $anchor = $this->database->getConnection()->fetchAssociative(
            'SELECT sequence, pruned_checkpoint_count FROM audit_checkpoint_succession',
        );
        $evidence = $this->database->getConnection()->fetchAssociative(
            'SELECT checkpoint_id, checkpoint_uuid, checkpoint_hash FROM audit_checkpoint_succession_pruned',
        );

        self::assertIsArray($anchor);
        self::assertSame(1, (int) $anchor['pruned_checkpoint_count']);
        self::assertIsArray($evidence);
        self::assertSame($checkpoint->getUuid(), $evidence['checkpoint_uuid']);
        self::assertSame($checkpoint->getCheckpointHash(), $evidence['checkpoint_hash']);
        self::assertTrue((new AuditChainVerifier(
            $this->database,
            custody: new AuditCheckpointCustody($this->keyring(2)),
        ))->verify()->ok);
    }

    #[Test]
    public function coordinator_rolls_back_parent_and_child_anchor_rows_when_child_evidence_insert_fails(): void
    {
        $checkpoint = $this->sealUnderVersion(1, 'atomic-pruned-at-cutover');
        $predecessorCustody = new AuditCheckpointCustody($this->keyring(1));
        $this->database->update('audit_checkpoint')->fields([
            'pruned' => 1,
            'prune_authorization' => $predecessorCustody->sealPruneAuthorization($checkpoint->getCheckpointHash()),
        ])->condition('uuid', $checkpoint->getUuid())->execute();
        $this->database->delete('audit_event')
            ->condition('id', $checkpoint->getSegmentStartId(), '>=')
            ->condition('id', $checkpoint->getSegmentEndId(), '<=')
            ->execute();
        $migration = require dirname(__DIR__, 4) . '/foundation/migrations/2026_08_15_000002_application_master_rekey.php';
        if (!$migration instanceof Migration) {
            throw new \LogicException('The Foundation application-master rekey migration is invalid.');
        }
        $migration->up(new SchemaBuilder($this->database->getConnection()));
        $purposes = $this->purposes();
        $keyring = $this->keyring(2, [1]);
        $requestId = 'audit-succession-atomic-failure-v1-v2';
        $store = new ApplicationMasterRekeyStore($this->database, static fn(): int => 1_200);
        $store->prepare(new ApplicationMasterRekeyRequest(
            requestId: $requestId,
            fromVersion: 1,
            toVersion: 2,
            registryChecksum: $purposes->checksum(),
            authorizationDigest: hash('sha256', 'audit-succession-atomic-authorization'),
            actor: 'test-operator',
            rollbackDeadline: 2_000,
            retentionDeadline: 3_000,
            createdAt: 1_000,
        ), $purposes);
        $store->installActive(
            $requestId,
            1,
            $keyring->referenceFingerprint(1),
            $keyring->referenceFingerprint(2),
            1_100,
        );
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);
        $coordinator = new ApplicationMasterRekeyCoordinator($store, $keyring, $purposes, [$adapter]);
        $coordinator->snapshotAdapter($requestId, 2, AuditCheckpointSuccessionRekeyAdapter::ID);
        $this->database->getConnection()->executeStatement(
            "CREATE TRIGGER refuse_audit_succession_child BEFORE INSERT ON audit_checkpoint_succession_pruned BEGIN SELECT RAISE(ABORT, 'synthetic child refusal'); END",
        );

        try {
            $coordinator->transitionNextBatch(
                $requestId,
                3,
                AuditCheckpointSuccessionRekeyAdapter::ID,
                1,
            );
            self::fail('A partially written audit succession anchor was committed.');
        } catch (ApplicationMasterRekeyConflictException $exception) {
            self::assertStringContainsString('recorded', $exception->getMessage());
        }

        self::assertSame(0, (int) $this->database->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_checkpoint_succession',
        ));
        self::assertSame(0, (int) $this->database->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_checkpoint_succession_pruned',
        ));
        $progress = $store->requireAdapter($requestId, AuditCheckpointSuccessionRekeyAdapter::ID);
        self::assertNull($progress->cursor);
        self::assertSame(1, $progress->unresolvedFailures);
        self::assertSame('adapter-transition-failed', $this->database->getConnection()->fetchOne(
            'SELECT failure_code FROM waaseyaa_application_master_rekey_failure WHERE resolved_at IS NULL',
        ));
    }

    #[Test]
    public function malformed_or_unversioned_predecessor_history_is_refused_before_anchor_creation(): void
    {
        $this->insertEvent('legacy-history');
        new AuditCheckpointBuilder(
            $this->database,
            $this->sink,
            hmacKey: hash('sha256', 'legacy-history-key', true),
        )->build();
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        $this->expectExceptionMessage('predecessor');
        $adapter->snapshot($this->context($this->database));
    }

    #[Test]
    public function an_unlisted_post_anchor_prune_requires_a_current_readable_authorization(): void
    {
        $this->sealUnderVersion(1, 'kept-at-cutover');
        $laterPruned = $this->sealUnderVersion(1, 'forged-after-cutover');
        $context = $this->context($this->database);
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);
        $snapshot = $adapter->snapshot($context);
        $adapter->transitionBatch($context, $snapshot, null, 1);
        $this->database->update('audit_checkpoint')->fields(['pruned' => 1])
            ->condition('uuid', $laterPruned->getUuid())->execute();
        $this->database->delete('audit_event')
            ->condition('id', $laterPruned->getSegmentStartId(), '>=')
            ->condition('id', $laterPruned->getSegmentEndId(), '<=')
            ->execute();

        $result = new AuditChainVerifier(
            $this->database,
            custody: new AuditCheckpointCustody($this->keyring(2)),
        )->verify();

        self::assertFalse($result->ok);
        self::assertSame('prune_authorization', $result->failureKind);
    }

    #[Test]
    public function every_operation_requires_the_exact_coordinator_database_authority(): void
    {
        $this->sealUnderVersion(1, 'wrong-authority');
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);
        $other = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::audit($other);

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        $this->expectExceptionMessage('exact coordinator database authority');
        $adapter->snapshot($this->context($other));
    }

    #[Test]
    public function predecessor_history_verifies_through_the_anchor_after_predecessor_removal(): void
    {
        $this->sealUnderVersion(1, 'survives-predecessor-removal');
        $context = $this->context($this->database);
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);
        $snapshot = $adapter->snapshot($context);
        $adapter->transitionBatch($context, $snapshot, null, 1);

        $result = new AuditChainVerifier(
            $this->database,
            custody: new AuditCheckpointCustody($this->keyring(2)),
        )->verify();

        self::assertTrue($result->ok);
    }

    #[Test]
    public function tampering_a_pinned_predecessor_signature_breaks_the_successor_anchor(): void
    {
        $checkpoint = $this->sealUnderVersion(1, 'tamper-pinned-signature');
        $context = $this->context($this->database);
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);
        $snapshot = $adapter->snapshot($context);
        $adapter->transitionBatch($context, $snapshot, null, 1);
        $this->database->update('audit_checkpoint')->fields([
            'signature' => AuditCheckpointCustody::CHECKPOINT_PREFIX . '1:' . str_repeat('A', 43),
        ])->condition('uuid', $checkpoint->getUuid())->execute();

        $result = new AuditChainVerifier(
            $this->database,
            custody: new AuditCheckpointCustody($this->keyring(2)),
        )->verify();

        self::assertFalse($result->ok);
        self::assertSame('succession_anchor', $result->failureKind);
    }

    #[Test]
    public function builder_refuses_successor_signing_until_a_verified_anchor_covers_the_latest_checkpoint(): void
    {
        $this->sealUnderVersion(1, 'successor-write-gate');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('succession anchor');
        $this->insertEvent('forbidden-successor-write');
        new AuditCheckpointBuilder(
            $this->database,
            $this->sink,
            custody: new AuditCheckpointCustody($this->keyring(2, [1])),
        )->build();
    }

    #[Test]
    public function builder_signs_with_the_successor_after_the_anchor_is_durable_and_verified(): void
    {
        $this->sealUnderVersion(1, 'pre-anchor-checkpoint');
        $context = $this->context($this->database);
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);
        $snapshot = $adapter->snapshot($context);
        $adapter->transitionBatch($context, $snapshot, null, 1);
        $this->insertEvent('post-anchor-checkpoint');

        $checkpoint = new AuditCheckpointBuilder(
            $this->database,
            $this->sink,
            custody: new AuditCheckpointCustody($this->keyring(2, [1])),
        )->build();

        self::assertNotNull($checkpoint);
        self::assertStringStartsWith(
            AuditCheckpointCustody::CHECKPOINT_PREFIX . '2:',
            $checkpoint->getSignature(),
        );
    }

    #[Test]
    public function a_later_anchor_transitively_verifies_history_after_intermediate_key_removal(): void
    {
        $this->sealUnderVersion(1, 'multi-hop-v1');
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);
        $firstContext = $this->contextVersions($this->database, 1, 2);
        $firstSnapshot = $adapter->snapshot($firstContext);
        $adapter->transitionBatch($firstContext, $firstSnapshot, null, 1);
        $this->insertEvent('multi-hop-v2');
        new AuditCheckpointBuilder(
            $this->database,
            $this->sink,
            custody: new AuditCheckpointCustody($this->keyring(2, [1])),
        )->build();
        $secondContext = $this->contextVersions($this->database, 2, 3);
        $secondSnapshot = $adapter->snapshot($secondContext);
        $adapter->transitionBatch($secondContext, $secondSnapshot, null, 1);
        $records = $this->database->getConnection()->fetchAllAssociative(
            'SELECT sequence, previous_ledger_hash, record_hash FROM audit_checkpoint_succession ORDER BY sequence',
        );

        self::assertCount(2, $records);
        self::assertSame($records[0]['record_hash'], $records[1]['previous_ledger_hash']);
        self::assertTrue((new AuditChainVerifier(
            $this->database,
            custody: new AuditCheckpointCustody($this->keyring(3)),
        ))->verify()->ok);
    }

    #[Test]
    public function child_evidence_inserted_after_anchor_commit_breaks_the_signed_count_and_digest(): void
    {
        $checkpoint = $this->sealUnderVersion(1, 'post-commit-child');
        $context = $this->context($this->database);
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);
        $snapshot = $adapter->snapshot($context);
        $adapter->transitionBatch($context, $snapshot, null, 1);
        $sequence = (int) $this->database->getConnection()->fetchOne(
            'SELECT sequence FROM audit_checkpoint_succession',
        );
        $this->database->insert('audit_checkpoint_succession_pruned')->values([
            'anchor_sequence' => $sequence,
            'checkpoint_id' => 1,
            'checkpoint_uuid' => $checkpoint->getUuid(),
            'checkpoint_hash' => $checkpoint->getCheckpointHash(),
        ])->execute();

        $result = new AuditChainVerifier(
            $this->database,
            custody: new AuditCheckpointCustody($this->keyring(2)),
        )->verify();

        self::assertFalse($result->ok);
        self::assertSame('succession_anchor', $result->failureKind);
    }

    #[Test]
    public function trailing_checkpoint_deletion_breaks_the_anchor_terminal_identity(): void
    {
        $checkpoint = $this->sealUnderVersion(1, 'trailing-deletion');
        $context = $this->context($this->database);
        $adapter = new AuditCheckpointSuccessionRekeyAdapter($this->database);
        $snapshot = $adapter->snapshot($context);
        $adapter->transitionBatch($context, $snapshot, null, 1);
        $this->database->delete('audit_checkpoint')->condition('uuid', $checkpoint->getUuid())->execute();

        $result = new AuditChainVerifier(
            $this->database,
            custody: new AuditCheckpointCustody($this->keyring(2)),
        )->verify();

        self::assertFalse($result->ok);
        self::assertSame('succession_anchor', $result->failureKind);
    }

    private function sealUnderVersion(int $version, string $uuid): AuditCheckpoint
    {
        $this->insertEvent($uuid);
        $checkpoint = new AuditCheckpointBuilder(
            $this->database,
            $this->sink,
            custody: new AuditCheckpointCustody($this->keyring($version)),
        )->build();
        self::assertNotNull($checkpoint);

        return $checkpoint;
    }

    private function insertEvent(string $uuid): void
    {
        $this->database->insert('audit_event')->values([
            'uuid' => $uuid,
            'event_kind' => 'entity.write',
            'account_uid' => 1,
            'actor_uid' => 1,
            'entity_type_id' => 'node',
            'entity_uuid' => 'eeeeeeee-0000-0000-0000-000000000001',
            'subject_uri' => '/entities/node/test',
            'outcome' => 'allowed',
            'severity' => 'info',
            'attributes' => '{}',
            'created_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ])->execute();
    }

    private function context(DatabaseInterface $database): ApplicationMasterRekeyContext
    {
        return $this->contextVersions($database, 1, 2);
    }

    private function contextVersions(
        DatabaseInterface $database,
        int $fromVersion,
        int $toVersion,
    ): ApplicationMasterRekeyContext {
        $keyring = $this->keyring($toVersion, [$fromVersion]);

        return new ApplicationMasterRekeyContext(
            new ApplicationMasterRekeyRecord(
                requestId: 'audit-succession-request-v' . $fromVersion . '-v' . $toVersion,
                requestDigest: hash('sha256', 'audit-succession-request-v' . $fromVersion . '-v' . $toVersion),
                fromVersion: $fromVersion,
                toVersion: $toVersion,
                registryChecksum: $keyring->purposeRegistryChecksum(),
                authorizationDigest: hash('sha256', 'audit-succession-authorization'),
                actor: 'test-operator',
                rollbackDeadline: 2_000,
                retentionDeadline: 3_000,
                state: ApplicationMasterRekeyState::TransitionBoundedBatches,
                revision: 1,
                unresolvedFailures: 0,
                createdAt: 1_000,
                updatedAt: 1_000,
            ),
            $keyring,
            $database,
        );
    }

    /** @param list<int> $legacyVersions */
    private function keyring(int $activeVersion, array $legacyVersions = []): ApplicationMasterKeyring
    {
        $purposes = $this->purposes();
        $resolver = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $resolver->registerProvider(new AuditSuccessionSyntheticMasterProvider());
        $resolver->allow(
            'audit-succession-synthetic-master',
            ApplicationMasterKeyring::PACKAGE,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
            ['testing'],
        );
        ApplicationMasterKeyring::registerResolverConsumers($resolver);
        $resolver->freeze();
        $legacyReferences = [];
        foreach ($legacyVersions as $version) {
            $legacyReferences[$version] = $this->reference($version);
        }

        return ApplicationMasterKeyring::fromReferences(
            $resolver,
            $activeVersion,
            $this->reference($activeVersion),
            $legacyReferences,
            $purposes,
        );
    }

    private function purposes(): ApplicationMasterPurposeRegistry
    {
        $purposes = new ApplicationMasterPurposeRegistry();
        $purposes->register(new ApplicationMasterPurposePolicy(
            id: ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
            ownerPackage: 'waaseyaa/audit',
            strategy: ApplicationMasterPurposeStrategy::RetainHistoricVerifier,
            maximumLifetimeSeconds: 0,
            retentionSeconds: 0,
            adapterId: AuditCheckpointSuccessionRekeyAdapter::ID,
            rollbackBehavior: 'append-authenticated-rollback-marker',
        ));
        $purposes->freeze();

        return $purposes;
    }

    private function reference(int $version): SecretReference
    {
        return SecretReference::create(
            'audit-succession-synthetic-master',
            'master-v' . $version,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
        );
    }
}

final class AuditSuccessionSyntheticMasterProvider implements SecretProviderInterface
{
    public function id(): string
    {
        return 'audit-succession-synthetic-master';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        return SensitiveValue::fromBytes(
            hash('sha256', $reference->identifier(), true),
            SecretClass::ApplicationMaster,
            $reference->identifier(),
        );
    }
}
