<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\ApplicationMasterAuthenticateOperation;
use Waaseyaa\Foundation\Security\ApplicationMasterAuthenticationTag;
use Waaseyaa\Foundation\Security\ApplicationMasterEnvelope;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterLookupOperation;
use Waaseyaa\Foundation\Security\ApplicationMasterOpenOperation;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationMasterSealOperation;
use Waaseyaa\Foundation\Security\ApplicationMasterVerifyOperation;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretConsumptionCode;
use Waaseyaa\Foundation\Security\SecretConsumptionException;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;

/** Retained-red core proof for the CFG-04 versioned application-master slice. */
final class ApplicationMasterKeyringRetainedRedTest extends TestCase
{
    #[Test]
    public function active_authentication_tags_remain_verifiable_after_rotation_without_exporting_a_key(): void
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
        $oldTag = $oldOnly->authenticate(
            ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
            'synthetic-checkpoint-v1',
        );
        $rotated = ApplicationMasterKeyring::fromReferences(
            resolver: $resolver,
            activeVersion: 2,
            activeReference: $this->reference('master-v2'),
            legacyReferences: [1 => $this->reference('master-v1')],
            purposes: $purposes,
        );
        $newTag = $rotated->authenticate(
            ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
            'synthetic-checkpoint-v2',
        );

        self::assertSame(1, $oldTag->masterVersion);
        self::assertSame(2, $newTag->masterVersion);
        self::assertTrue($rotated->verify($oldTag, 'synthetic-checkpoint-v1'));
        self::assertTrue($rotated->verify($newTag, 'synthetic-checkpoint-v2'));
        self::assertFalse($rotated->verify($oldTag, 'tampered-checkpoint'));
        self::assertSame($oldTag, ApplicationMasterAuthenticationTag::fromArray($oldTag->toArray()));
    }

    #[Test]
    public function lookup_candidates_cover_each_declared_readable_version_without_permitting_legacy_writes(): void
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
        $oldCandidate = $oldOnly->lookupCandidates(
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
            'synthetic-opaque-token',
        )[0];
        $rotated = ApplicationMasterKeyring::fromReferences(
            resolver: $resolver,
            activeVersion: 2,
            activeReference: $this->reference('master-v2'),
            legacyReferences: [1 => $this->reference('master-v1')],
            purposes: $purposes,
        );
        $candidates = $rotated->lookupCandidates(
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
            'synthetic-opaque-token',
        );

        self::assertSame([1, 2], array_column(array_map(
            static fn(ApplicationMasterAuthenticationTag $tag): array => $tag->toArray(),
            $candidates,
        ), 'master_version'));
        self::assertSame($oldCandidate->digest, $candidates[0]->digest);
        self::assertNotSame($candidates[0]->digest, $candidates[1]->digest);

        $handles = (new \ReflectionProperty($rotated, 'handles'))->getValue($rotated);
        self::assertIsArray($handles);
        $allowed = (new \ReflectionProperty($handles[1], 'allowedConsumers'))->getValue($handles[1]);
        self::assertSame(
            [
                ApplicationMasterOpenOperation::class,
                ApplicationMasterVerifyOperation::class,
                ApplicationMasterLookupOperation::class,
            ],
            array_keys($allowed),
        );
        self::assertArrayNotHasKey(ApplicationMasterAuthenticateOperation::class, $allowed);
    }

    #[Test]
    public function authentication_and_lookup_strategy_refusals_happen_before_external_resolution(): void
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
            $keyring->authenticate(
                ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
                'synthetic-message',
            );
            self::fail('A ciphertext purpose created an authentication tag.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $provider->resolvedIdentifiers);
        }

        try {
            $keyring->lookupCandidates(
                ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
                'synthetic-message',
            );
            self::fail('A non-lookup purpose created lookup candidates.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $provider->resolvedIdentifiers);
        }
    }

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
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
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
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
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
        $provider = $this->provider();
        $keyring = ApplicationMasterKeyring::fromReferences(
            resolver: $this->resolver($provider),
            activeVersion: 2,
            activeReference: $this->reference('master-v2'),
            legacyReferences: [1 => $this->reference('master-v1')],
            purposes: $this->purposes(),
        );
        $envelope = $keyring->seal(
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
            'oidc-token:row-7',
            3,
            'synthetic-plaintext',
        );

        foreach (['master_version', 'purpose', 'record_identity', 'schema_version'] as $field) {
            $document = $envelope->toArray();
            $document[$field] = match ($field) {
                'master_version' => 1,
                'purpose' => ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
                'record_identity' => 'oidc-token:row-8',
                default => 4,
            };
            try {
                $keyring->open(ApplicationMasterEnvelope::fromArray($document));
                self::fail(sprintf('Tampered application-master envelope field %s was accepted.', $field));
            } catch (SecretConsumptionException $exception) {
                self::assertSame(SecretConsumptionCode::AuthenticationFailed, $exception->reason);
            }
        }

        $document = $envelope->toArray();
        $ciphertext = base64_decode($document['ciphertext'], true);
        self::assertIsString($ciphertext);
        $ciphertext[0] = chr(ord($ciphertext[0]) ^ 1);
        $document['ciphertext'] = base64_encode($ciphertext);
        try {
            $keyring->open(ApplicationMasterEnvelope::fromArray($document));
            self::fail('Tampered application-master ciphertext was accepted.');
        } catch (SecretConsumptionException $exception) {
            self::assertSame(SecretConsumptionCode::AuthenticationFailed, $exception->reason);
        }
    }

    #[Test]
    public function authenticated_decryption_failure_retains_a_distinct_non_secret_reason(): void
    {
        $keyring = ApplicationMasterKeyring::fromReferences(
            resolver: $this->resolver($this->provider()),
            activeVersion: 1,
            activeReference: $this->reference('master-v1'),
            legacyReferences: [],
            purposes: $this->purposes(),
        );
        $document = $keyring->seal(
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
            'record:authenticated-refusal',
            1,
            'synthetic-plaintext',
        )->toArray();
        $ciphertext = base64_decode($document['ciphertext'], true);
        self::assertIsString($ciphertext);
        $ciphertext[0] = chr(ord($ciphertext[0]) ^ 1);
        $document['ciphertext'] = base64_encode($ciphertext);

        try {
            $keyring->open(ApplicationMasterEnvelope::fromArray($document));
            self::fail('Tampered ciphertext was accepted.');
        } catch (SecretConsumptionException $exception) {
            self::assertSame('SECRET_CONSUMER_AUTHENTICATION_FAILED', $exception->reason->value);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('synthetic-plaintext', $exception->getMessage());
        }
    }

    #[Test]
    public function invalid_seal_metadata_refuses_before_external_custody_resolution(): void
    {
        $provider = $this->provider();
        $keyring = ApplicationMasterKeyring::fromReferences(
            resolver: $this->resolver($provider),
            activeVersion: 1,
            activeReference: $this->reference('master-v1'),
            legacyReferences: [],
            purposes: $this->purposes(),
        );

        foreach ([['', 1], ["record:\0control", 1], [str_repeat('x', 513), 1], ['record:valid', 0]] as [$identity, $schemaVersion]) {
            try {
                $keyring->seal(
                    ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
                    $identity,
                    $schemaVersion,
                    'synthetic-plaintext',
                );
                self::fail('Invalid envelope metadata was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $provider->resolvedIdentifiers);
            }
        }
    }

    #[Test]
    public function legacy_handles_are_read_only_and_resolver_freeze_is_a_construction_precondition(): void
    {
        $provider = $this->provider();
        $unfrozen = $this->resolver($provider, false);
        try {
            ApplicationMasterKeyring::fromReferences(
                resolver: $unfrozen,
                activeVersion: 1,
                activeReference: $this->reference('master-v1'),
                legacyReferences: [],
                purposes: $this->purposes(),
            );
            self::fail('An application-master keyring accepted an unfrozen resolver.');
        } catch (\LogicException) {
            self::assertSame([], $provider->resolvedIdentifiers);
        }

        $keyring = ApplicationMasterKeyring::fromReferences(
            resolver: $this->resolver($provider),
            activeVersion: 2,
            activeReference: $this->reference('master-v2'),
            legacyReferences: [1 => $this->reference('master-v1')],
            purposes: $this->purposes(),
        );
        $handlesProperty = new \ReflectionProperty($keyring, 'handles');
        $handles = $handlesProperty->getValue($keyring);
        self::assertIsArray($handles);
        $allowedProperty = new \ReflectionProperty($handles[1], 'allowedConsumers');
        self::assertSame(
            [
                ApplicationMasterOpenOperation::class,
                ApplicationMasterVerifyOperation::class,
                ApplicationMasterLookupOperation::class,
            ],
            array_keys($allowedProperty->getValue($handles[1])),
        );
    }

    #[Test]
    public function seal_operation_plaintext_is_non_exporting(): void
    {
        $operation = new ApplicationMasterSealOperation(
            1,
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
            'record:non-exporting',
            1,
            'synthetic-operation-plaintext',
        );
        ob_start();
        var_dump($operation);
        $diagnostic = (string) ob_get_clean();
        self::assertStringNotContainsString('synthetic-operation-plaintext', $diagnostic);

        $this->expectException(\LogicException::class);
        serialize($operation);
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
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
            'record:2',
            1,
            'plaintext',
        )->toArray();
        $provider->resolvedIdentifiers = [];
        $document['purpose'] = 'waaseyaa.unregistered-but-valid.v1';
        try {
            $keyring->open(ApplicationMasterEnvelope::fromArray($document));
            self::fail('Unregistered application-master envelope purpose was accepted.');
        } catch (\RuntimeException) {
            self::assertSame([], $provider->resolvedIdentifiers);
        }

        $document['master_version'] = 99;
        $document['purpose'] = ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION;
        try {
            $keyring->open(ApplicationMasterEnvelope::fromArray($document));
            self::fail('Unknown application-master version was accepted.');
        } catch (\RuntimeException) {
            self::assertSame([], $provider->resolvedIdentifiers);
        }
    }

    #[Test]
    public function envelope_parser_requires_exact_fields_types_and_canonical_encodings(): void
    {
        $document = ApplicationMasterEnvelope::seal(
            1,
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
            'record:strict-parser',
            1,
            str_repeat('n', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            str_repeat('c', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES),
        )->toArray();

        $invalid = [
            array_merge($document, ['extension' => 'not-allowed']),
            array_diff_key($document, ['schema_version' => true]),
            array_merge($document, ['master_version' => '1']),
            array_merge($document, ['nonce' => $document['nonce'] . '=']),
            array_merge($document, ['ciphertext' => '*invalid-base64*']),
        ];
        foreach ($invalid as $candidate) {
            try {
                ApplicationMasterEnvelope::fromArray($candidate);
                self::fail('Malformed application-master envelope was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function keyring_topology_and_reference_contract_fail_before_provider_resolution(): void
    {
        $provider = $this->provider();
        $resolver = $this->resolver($provider);
        $active = $this->reference('master-v2');
        $invalidCases = [
            [0, $active, []],
            [2, $active, [2 => $this->reference('master-v1')]],
            [2, $active, [1 => $active]],
            [2, SecretReference::create(
                'synthetic-master-vault',
                'tenant/application/master-v2',
                SecretClass::ApplicationMaster,
                'waaseyaa.application.wrong-purpose.v1',
            ), []],
        ];

        foreach ($invalidCases as [$activeVersion, $activeReference, $legacyReferences]) {
            try {
                ApplicationMasterKeyring::fromReferences(
                    $resolver,
                    $activeVersion,
                    $activeReference,
                    $legacyReferences,
                    $this->purposes(),
                );
                self::fail('Invalid application-master keyring topology was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $provider->resolvedIdentifiers);
            }
        }
    }

    #[Test]
    public function transition_strategy_vocabulary_is_closed_and_exact(): void
    {
        self::assertSame(
            [
                'reencrypt-ciphertext',
                'recompute-lookup-index',
                'retain-historic-verifier',
                'invalidate-rebuildable',
                'drain-or-expire',
                'ephemeral-no-persistence',
            ],
            array_map(
                static fn(ApplicationMasterPurposeStrategy $strategy): string => $strategy->value,
                ApplicationMasterPurposeStrategy::cases(),
            ),
        );
    }

    #[Test]
    public function purpose_policy_and_registry_refuse_incomplete_or_ambiguous_metadata(): void
    {
        $registry = new ApplicationMasterPurposeRegistry();
        try {
            $registry->freeze();
            self::fail('An empty application-master purpose registry was frozen.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        $registry->register($this->reencryptPolicy());
        try {
            $registry->register($this->reencryptPolicy());
            self::fail('A duplicate application-master purpose was registered.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        new ApplicationMasterPurposePolicy(
            id: 'waaseyaa.invalid-retention.v1',
            ownerPackage: 'waaseyaa/foundation',
            strategy: ApplicationMasterPurposeStrategy::RetainHistoricVerifier,
            maximumLifetimeSeconds: 86_400,
            retentionSeconds: 3_600,
            adapterId: 'synthetic-audit-v1',
            rollbackBehavior: 'retain-successor-anchor',
        );
    }

    #[Test]
    public function purpose_registry_is_order_stable_closed_and_frozen_before_derivation(): void
    {
        $first = $this->purposes();
        $second = new ApplicationMasterPurposeRegistry();
        $second->register($this->lookupPolicy());
        $second->register($this->accessTokenEncryptionPolicy());
        $second->register($this->auditPolicy());
        $second->register($this->reencryptPolicy());
        $second->freeze();

        self::assertSame($first->checksum(), $second->checksum());
        self::assertSame(
            [
                ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
                ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
                ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
                ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
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
        ob_start();
        var_dump($keyring);
        $debugDiagnostic = (string) ob_get_clean();

        foreach ($provider->masters as $master) {
            self::assertStringNotContainsString(base64_encode($master), $diagnostic);
        }
        self::assertStringNotContainsString('tenant/application/master-v', $diagnostic);
        self::assertStringContainsString('[NON_EXPORTING]', $debugDiagnostic);
        self::assertStringNotContainsString('tenant/application/master-v', $debugDiagnostic);
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
        $registry->register($this->accessTokenEncryptionPolicy());
        $registry->register($this->auditPolicy());
        $registry->freeze();

        return $registry;
    }

    private function reencryptPolicy(): ApplicationMasterPurposePolicy
    {
        return new ApplicationMasterPurposePolicy(
            id: ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
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
            id: ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
            ownerPackage: 'waaseyaa/oidc',
            strategy: ApplicationMasterPurposeStrategy::RecomputeLookupIndex,
            maximumLifetimeSeconds: 3_600,
            retentionSeconds: 86_400,
            adapterId: 'synthetic-oidc-row-v1',
            rollbackBehavior: 'reverse-cas',
        );
    }

    private function accessTokenEncryptionPolicy(): ApplicationMasterPurposePolicy
    {
        return new ApplicationMasterPurposePolicy(
            id: ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
            ownerPackage: 'waaseyaa/oidc',
            strategy: ApplicationMasterPurposeStrategy::ReencryptCiphertext,
            maximumLifetimeSeconds: 3_600,
            retentionSeconds: 86_400,
            adapterId: 'synthetic-oidc-row-v1',
            rollbackBehavior: 'reverse-cas',
        );
    }

    private function auditPolicy(): ApplicationMasterPurposePolicy
    {
        return new ApplicationMasterPurposePolicy(
            id: ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
            ownerPackage: 'waaseyaa/audit',
            strategy: ApplicationMasterPurposeStrategy::RetainHistoricVerifier,
            maximumLifetimeSeconds: 86_400,
            retentionSeconds: 604_800,
            adapterId: 'synthetic-audit-checkpoint-v1',
            rollbackBehavior: 'retain-successor-anchor',
        );
    }

    private function resolver(SyntheticApplicationMasterProvider $provider, bool $freeze = true): SecretResolverRegistry
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
        if ($freeze) {
            $registry->freeze();
        }

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
