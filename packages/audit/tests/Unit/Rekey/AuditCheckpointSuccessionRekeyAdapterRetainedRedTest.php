<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Rekey;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Entity\AuditCheckpoint;
use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Audit\Integrity\AuditCheckpointCustody;
use Waaseyaa\Audit\Integrity\CheckpointSink;
use Waaseyaa\Audit\Rekey\AuditCheckpointSuccessionRekeyAdapter;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyRecord;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyState;
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
        $keyring = $this->keyring(2, [1]);

        return new ApplicationMasterRekeyContext(
            new ApplicationMasterRekeyRecord(
                requestId: 'audit-succession-request',
                requestDigest: hash('sha256', 'audit-succession-request'),
                fromVersion: 1,
                toVersion: 2,
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
