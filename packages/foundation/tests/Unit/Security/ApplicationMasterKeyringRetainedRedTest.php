<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\ApplicationMasterEnvelope;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;

/** Retained-red core proof for the CFG-04 versioned application-master slice. */
final class ApplicationMasterKeyringRetainedRedTest extends TestCase
{
    #[Test]
    public function new_writes_use_only_the_active_version_while_declared_legacy_envelopes_remain_readable(): void
    {
        $provider = $this->provider();
        $purposes = $this->purposes();
        $resolver = $this->resolver($provider);
        $oldOnly = ApplicationMasterKeyring::fromReferences(
            resolver: $resolver,
            activeVersion: 1,
            activeReference: $this->reference('master-v1'),
            legacyReferences: [],
            purposes: $purposes,
        );
        $oldEnvelope = $oldOnly->seal(
            ApplicationMasterPurposePolicy::TEST_REENCRYPT_PURPOSE,
            'oidc-token:old-row',
            3,
            'synthetic-old-plaintext',
        );
        $rotated = ApplicationMasterKeyring::fromReferences(
            resolver: $resolver,
            activeVersion: 2,
            activeReference: $this->reference('master-v2'),
            legacyReferences: [1 => $this->reference('master-v1')],
            purposes: $purposes,
        );
        $newEnvelope = $rotated->seal(
            ApplicationMasterPurposePolicy::TEST_REENCRYPT_PURPOSE,
            'oidc-token:new-row',
            3,
            'synthetic-new-plaintext',
        );

        self::assertSame(1, $oldEnvelope->masterVersion);
        self::assertSame(2, $newEnvelope->masterVersion);
        self::assertSame('synthetic-old-plaintext', $rotated->open($oldEnvelope));
        self::assertSame('synthetic-new-plaintext', $rotated->open($newEnvelope));
    }

    #[Test]
    public function envelope_authenticates_master_version_purpose_record_and_schema_identity(): void
    {
        $keyring = ApplicationMasterKeyring::fromReferences(
            resolver: $this->resolver($this->provider()),
            activeVersion: 2,
            activeReference: $this->reference('master-v2'),
            legacyReferences: [1 => $this->reference('master-v1')],
            purposes: $this->purposes(),
        );
        $envelope = $keyring->seal(
            ApplicationMasterPurposePolicy::TEST_REENCRYPT_PURPOSE,
            'oidc-token:row-7',
            3,
            'synthetic-plaintext',
        );

        foreach (['purpose', 'record_identity', 'schema_version'] as $field) {
            $document = $envelope->toArray();
            $document[$field] = match ($field) {
                'purpose' => ApplicationMasterPurposePolicy::TEST_LOOKUP_PURPOSE,
                'record_identity' => 'oidc-token:row-8',
                default => 4,
            };
            try {
                $keyring->open(ApplicationMasterEnvelope::fromArray($document));
                self::fail(sprintf('Tampered application-master envelope field %s was accepted.', $field));
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function unknown_version_or_unregistered_purpose_refuses_without_arbitrary_resolution_fallback(): void
    {
        $provider = $this->provider();
        $keyring = ApplicationMasterKeyring::fromReferences(
            resolver: $this->resolver($provider),
            activeVersion: 2,
            activeReference: $this->reference('master-v2'),
            legacyReferences: [1 => $this->reference('master-v1')],
            purposes: $this->purposes(),
        );

        try {
            $keyring->seal('waaseyaa.unregistered-but-valid.v1', 'record:1', 1, 'plaintext');
            self::fail('Unregistered application-master purpose was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $provider->resolvedIdentifiers);
        }

        $document = $keyring->seal(
            ApplicationMasterPurposePolicy::TEST_REENCRYPT_PURPOSE,
            'record:2',
            1,
            'plaintext',
        )->toArray();
        $provider->resolvedIdentifiers = [];
        $document['master_version'] = 99;
        try {
            $keyring->open(ApplicationMasterEnvelope::fromArray($document));
            self::fail('Unknown application-master version was accepted.');
        } catch (\RuntimeException) {
            self::assertSame([], $provider->resolvedIdentifiers);
        }
    }

    #[Test]
    public function purpose_registry_is_order_stable_closed_and_frozen_before_derivation(): void
    {
        $first = $this->purposes();
        $second = new ApplicationMasterPurposeRegistry();
        $second->register($this->lookupPolicy());
        $second->register($this->reencryptPolicy());
        $second->freeze();

        self::assertSame($first->checksum(), $second->checksum());
        self::assertSame(
            [
                ApplicationMasterPurposePolicy::TEST_LOOKUP_PURPOSE,
                ApplicationMasterPurposePolicy::TEST_REENCRYPT_PURPOSE,
            ],
            $first->purposeIds(),
        );

        $this->expectException(\LogicException::class);
        $first->register($this->reencryptPolicy());
    }

    #[Test]
    public function keyring_diagnostics_serialization_and_clone_surfaces_disclose_no_master_material_or_provider_path(): void
    {
        $provider = $this->provider();
        $keyring = ApplicationMasterKeyring::fromReferences(
            resolver: $this->resolver($provider),
            activeVersion: 2,
            activeReference: $this->reference('master-v2'),
            legacyReferences: [1 => $this->reference('master-v1')],
            purposes: $this->purposes(),
        );
        $diagnostic = var_export($keyring, true);

        foreach ($provider->masters as $master) {
            self::assertStringNotContainsString(base64_encode($master), $diagnostic);
        }
        self::assertStringNotContainsString('tenant/application/master-v', $diagnostic);
        try {
            clone $keyring;
            self::fail('Application-master keyring was cloneable.');
        } catch (\Error) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\LogicException::class);
        serialize($keyring);
    }

    private function purposes(): ApplicationMasterPurposeRegistry
    {
        $registry = new ApplicationMasterPurposeRegistry();
        $registry->register($this->reencryptPolicy());
        $registry->register($this->lookupPolicy());
        $registry->freeze();

        return $registry;
    }

    private function reencryptPolicy(): ApplicationMasterPurposePolicy
    {
        return new ApplicationMasterPurposePolicy(
            id: ApplicationMasterPurposePolicy::TEST_REENCRYPT_PURPOSE,
            ownerPackage: 'waaseyaa/oidc',
            strategy: ApplicationMasterPurposeStrategy::ReencryptCiphertext,
            maximumLifetimeSeconds: 3_600,
            retentionSeconds: 86_400,
            adapterId: 'synthetic-oidc-row-v1',
            rollbackBehavior: 'reverse-cas',
        );
    }

    private function lookupPolicy(): ApplicationMasterPurposePolicy
    {
        return new ApplicationMasterPurposePolicy(
            id: ApplicationMasterPurposePolicy::TEST_LOOKUP_PURPOSE,
            ownerPackage: 'waaseyaa/oidc',
            strategy: ApplicationMasterPurposeStrategy::RecomputeLookupIndex,
            maximumLifetimeSeconds: 3_600,
            retentionSeconds: 86_400,
            adapterId: 'synthetic-oidc-row-v1',
            rollbackBehavior: 'reverse-cas',
        );
    }

    private function resolver(SyntheticApplicationMasterProvider $provider): SecretResolverRegistry
    {
        $registry = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $registry->registerProvider($provider);
        ApplicationMasterKeyring::registerResolverConsumers($registry);
        $registry->allow(
            $provider->id(),
            ApplicationMasterKeyring::PACKAGE,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
            ['testing'],
        );
        $registry->freeze();

        return $registry;
    }

    private function reference(string $version): SecretReference
    {
        return SecretReference::create(
            'synthetic-master-vault',
            'tenant/application/' . $version,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
        );
    }

    private function provider(): SyntheticApplicationMasterProvider
    {
        return new SyntheticApplicationMasterProvider([
            'tenant/application/master-v1' => hash('sha256', 'synthetic-master-v1', true),
            'tenant/application/master-v2' => hash('sha256', 'synthetic-master-v2', true),
        ]);
    }
}

final class SyntheticApplicationMasterProvider implements SecretProviderInterface
{
    /** @var list<string> */
    public array $resolvedIdentifiers = [];

    /** @param array<string, string> $masters */
    public function __construct(public readonly array $masters) {}

    public function id(): string
    {
        return 'synthetic-master-vault';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        $identifier = $reference->identifier();
        $this->resolvedIdentifiers[] = $identifier;
        $bytes = $this->masters[$identifier] ?? throw new \RuntimeException('unknown synthetic master');

        return SensitiveValue::fromBytes(
            $bytes,
            SecretClass::ApplicationMaster,
            str_ends_with($identifier, 'v1') ? 'master-v1' : 'master-v2',
        );
    }
}
