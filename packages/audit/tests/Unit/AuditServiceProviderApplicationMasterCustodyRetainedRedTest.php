<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\AuditServiceProvider;
use Waaseyaa\Audit\Integrity\AuditCheckpointCustody;
use Waaseyaa\Audit\Rekey\AuditCheckpointSuccessionRekeyAdapter;
use Waaseyaa\Database\DatabaseInterface;
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
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface;
use Waaseyaa\Testing\Kernel\KernelServicesFixture;

/** Retained-red proof for the installed audit owner and runtime custody boundary. */
final class AuditServiceProviderApplicationMasterCustodyRetainedRedTest extends TestCase
{
    private DBALDatabase $database;
    private ApplicationSecret $legacySecret;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite(':memory:');
        $this->legacySecret = ApplicationSecret::fromEnvironmentValue(
            'base64:' . base64_encode(str_repeat('L', 32)),
            'testing',
        );
    }

    #[Test]
    public function provider_contributes_the_exact_audit_succession_owner(): void
    {
        $provider = $this->provider([]);

        self::assertInstanceOf(ProvidesApplicationMasterRekeyContributionsInterface::class, $provider);
        $contributions = iterator_to_array($provider->applicationMasterRekeyContributions(), false);
        self::assertCount(1, $contributions);
        self::assertInstanceOf(AuditCheckpointSuccessionRekeyAdapter::class, $contributions[0]->adapter());
        self::assertSame($this->database, $contributions[0]->adapter()->databaseAuthority());
        self::assertSame([[
            'id' => ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
            'owner_package' => 'waaseyaa/audit',
            'strategy' => ApplicationMasterPurposeStrategy::RetainHistoricVerifier->value,
            'maximum_lifetime_seconds' => 0,
            'retention_seconds' => 0,
            'adapter_id' => AuditCheckpointSuccessionRekeyAdapter::ID,
            'rollback_behavior' => 'append-authenticated-rollback-marker',
        ]], array_map(
            static fn(ApplicationMasterPurposePolicy $policy): array => $policy->canonicalRecord(),
            $contributions[0]->policies(),
        ));
    }

    #[Test]
    public function keyring_runtime_does_not_require_or_accept_the_legacy_secret_by_default(): void
    {
        $custody = $this->provider([
            ApplicationMasterKeyring::class => $this->keyring(),
        ])->resolve(AuditCheckpointCustody::class);
        assert($custody instanceof AuditCheckpointCustody);
        $checkpointHash = hash('sha256', 'provider-keyring-only');

        self::assertStringStartsWith(
            AuditCheckpointCustody::CHECKPOINT_PREFIX . '2:',
            $custody->sealCheckpoint($checkpointHash),
        );
        self::assertFalse($custody->verifyCheckpoint($this->legacyEnvelope($checkpointHash), $checkpointHash));
    }

    #[Test]
    public function legacy_bridge_is_available_only_when_explicitly_configured(): void
    {
        $custody = $this->provider(
            [
                ApplicationMasterKeyring::class => $this->keyring(),
                ApplicationSecret::class => $this->legacySecret,
            ],
            ['audit' => ['accept_legacy_application_secret_checkpoints' => true]],
        )->resolve(AuditCheckpointCustody::class);
        assert($custody instanceof AuditCheckpointCustody);
        $checkpointHash = hash('sha256', 'provider-explicit-legacy-bridge');

        self::assertTrue($custody->verifyCheckpoint($this->legacyEnvelope($checkpointHash), $checkpointHash));
        self::assertStringStartsWith(
            AuditCheckpointCustody::CHECKPOINT_PREFIX . '2:',
            $custody->sealCheckpoint($checkpointHash),
        );
    }

    #[Test]
    public function installations_without_a_keyring_keep_the_legacy_runtime_compatible(): void
    {
        $custody = $this->provider([
            ApplicationSecret::class => $this->legacySecret,
        ])->resolve(AuditCheckpointCustody::class);
        assert($custody instanceof AuditCheckpointCustody);

        self::assertStringStartsWith(
            'hmac-sha256.hkdf-v1:',
            $custody->sealCheckpoint(hash('sha256', 'provider-legacy-only')),
        );
    }

    /** @param array<string, object> $services @param array<string, mixed> $config */
    private function provider(array $services, array $config = []): AuditServiceProvider
    {
        $provider = new AuditServiceProvider();
        $provider->setKernelContext('', $config, []);
        $provider->setKernelServices(new KernelServicesFixture([
            DatabaseInterface::class => $this->database,
            ...$services,
        ]));
        $provider->register();

        return $provider;
    }

    private function keyring(): ApplicationMasterKeyring
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
        $resolver->registerProvider(new AuditProviderSyntheticMasterProvider());
        $resolver->allow(
            'audit-provider-synthetic-master',
            ApplicationMasterKeyring::PACKAGE,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
            ['testing'],
        );
        ApplicationMasterKeyring::registerResolverConsumers($resolver);
        $resolver->freeze();

        return ApplicationMasterKeyring::fromReferences(
            $resolver,
            2,
            $this->reference(2),
            [1 => $this->reference(1)],
            $purposes,
        );
    }

    private function reference(int $version): SecretReference
    {
        return SecretReference::create(
            'audit-provider-synthetic-master',
            'master-v' . $version,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
        );
    }

    private function legacyEnvelope(string $checkpointHash): string
    {
        return 'hmac-sha256.hkdf-v1:' . hash_hmac(
            'sha256',
            $checkpointHash,
            $this->legacySecret->derive(ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC),
        );
    }
}

final class AuditProviderSyntheticMasterProvider implements SecretProviderInterface
{
    public function id(): string
    {
        return 'audit-provider-synthetic-master';
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
