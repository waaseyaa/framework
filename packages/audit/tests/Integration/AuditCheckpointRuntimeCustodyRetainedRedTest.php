<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Entity\AuditCheckpoint;
use Waaseyaa\Audit\Integrity\AuditChainVerifier;
use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Audit\Integrity\AuditCheckpointCustody;
use Waaseyaa\Audit\Integrity\CheckpointSink;
use Waaseyaa\Audit\Integrity\LegacyCheckpointSignatureMigrator;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/** Retained-red proof that audit runtime consumers use version-bound custody. */
final class AuditCheckpointRuntimeCustodyRetainedRedTest extends TestCase
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
    public function builder_and_verifier_use_version_bound_application_master_custody(): void
    {
        $this->insertEvent('runtime-custody-v1');
        $predecessor = new AuditCheckpointCustody($this->keyring(1));
        $checkpoint = new AuditCheckpointBuilder(
            $this->database,
            $this->sink,
            custody: $predecessor,
        )->build();

        self::assertNotNull($checkpoint);
        self::assertStringStartsWith(
            'hmac-sha256.application-master.audit-checkpoint.v1:1:',
            $checkpoint->getSignature(),
        );
        $result = new AuditChainVerifier(
            $this->database,
            custody: new AuditCheckpointCustody($this->keyring(2, [1])),
        )->verify();
        self::assertTrue($result->ok);
    }

    #[Test]
    public function verifier_refuses_a_checkpoint_version_that_is_not_declared_readable(): void
    {
        $this->insertEvent('runtime-custody-unknown');
        new AuditCheckpointBuilder(
            $this->database,
            $this->sink,
            custody: new AuditCheckpointCustody($this->keyring(1)),
        )->build();

        $result = new AuditChainVerifier(
            $this->database,
            custody: new AuditCheckpointCustody($this->keyring(2)),
        )->verify();

        self::assertFalse($result->ok);
        self::assertSame('checkpoint_signature', $result->failureKind);
    }

    #[Test]
    public function legacy_named_hmac_key_construction_remains_compatible(): void
    {
        $this->insertEvent('runtime-custody-legacy');
        $legacyKey = hash('sha256', 'runtime-legacy-key', true);
        $checkpoint = new AuditCheckpointBuilder(
            $this->database,
            $this->sink,
            hmacKey: $legacyKey,
        )->build();

        self::assertNotNull($checkpoint);
        self::assertStringStartsWith('hmac-sha256.hkdf-v1:', $checkpoint->getSignature());
        self::assertTrue((new AuditChainVerifier(
            $this->database,
            hmacKey: $legacyKey,
        ))->verify()->ok);
    }

    #[Test]
    public function explicit_migration_upgrades_legacy_signatures_to_the_active_master_version(): void
    {
        $legacyKey = hash('sha256', 'runtime-migration-legacy-key');
        $this->insertEvent('runtime-migration-versioned');
        new AuditCheckpointBuilder(
            $this->database,
            $this->sink,
            hmacKey: $legacyKey,
        )->build();
        $custody = new AuditCheckpointCustody($this->keyring(2), $legacyKey);

        $migrated = new LegacyCheckpointSignatureMigrator(
            $this->database,
            custody: $custody,
        )->migrate();

        self::assertSame(2, $migrated);
        $signatures = $this->database->getConnection()->fetchFirstColumn(
            'SELECT signature FROM audit_checkpoint ORDER BY id',
        );
        foreach ($signatures as $signature) {
            self::assertStringStartsWith(
                AuditCheckpointCustody::CHECKPOINT_PREFIX . '2:',
                (string) $signature,
            );
        }
        self::assertTrue((new AuditChainVerifier($this->database, custody: $custody))->verify()->ok);
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
            adapterId: 'audit-checkpoint-succession-v1',
            rollbackBehavior: 'append-authenticated-rollback-marker',
        ));
        $purposes->freeze();
        $resolver = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $resolver->registerProvider(new AuditRuntimeSyntheticMasterProvider());
        $resolver->allow(
            'audit-runtime-synthetic-master',
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
            'audit-runtime-synthetic-master',
            'master-v' . $version,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
        );
    }
}

final class AuditRuntimeSyntheticMasterProvider implements SecretProviderInterface
{
    public function id(): string
    {
        return 'audit-runtime-synthetic-master';
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
