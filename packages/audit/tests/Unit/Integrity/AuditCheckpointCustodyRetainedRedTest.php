<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Integrity\AuditCheckpointCustody;
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

/** Retained-red proof for version-bound audit checkpoint custody. */
final class AuditCheckpointCustodyRetainedRedTest extends TestCase
{
    #[Test]
    public function active_checkpoint_signatures_are_version_bound_and_predecessors_remain_verifiable(): void
    {
        $predecessor = new AuditCheckpointCustody($this->keyring(1));
        $signature = $predecessor->sealCheckpoint(str_repeat('a', 64));
        $rotated = new AuditCheckpointCustody($this->keyring(2, [1]));

        self::assertMatchesRegularExpression(
            '/^hmac-sha256\.application-master\.audit-checkpoint\.v1:1:[A-Za-z0-9_-]{43}$/D',
            $signature,
        );
        self::assertTrue($rotated->verifyCheckpoint($signature, str_repeat('a', 64)));
        self::assertFalse($rotated->verifyCheckpoint($signature, str_repeat('b', 64)));
    }

    #[Test]
    public function an_unknown_checkpoint_version_is_refused_without_fallback(): void
    {
        $custody = new AuditCheckpointCustody($this->keyring(2, [1]));
        $signature = (new AuditCheckpointCustody($this->keyring(1)))
            ->sealCheckpoint(str_repeat('c', 64));
        $unknown = preg_replace('/:1:/', ':7:', $signature, 1);

        self::assertIsString($unknown);
        self::assertFalse($custody->verifyCheckpoint($unknown, str_repeat('c', 64)));
    }

    #[Test]
    public function checkpoint_prune_and_succession_messages_are_cryptographically_separated(): void
    {
        $custody = new AuditCheckpointCustody($this->keyring(2, [1]));
        $message = str_repeat('d', 64);

        $checkpoint = $custody->sealCheckpoint($message);
        $prune = $custody->sealPruneAuthorization($message);
        $succession = $custody->sealSuccessionAnchor($message);

        self::assertNotSame($checkpoint, $prune);
        self::assertNotSame($checkpoint, $succession);
        self::assertNotSame($prune, $succession);
        self::assertTrue($custody->verifyCheckpoint($checkpoint, $message));
        self::assertTrue($custody->verifyPruneAuthorization($prune, $message));
        self::assertTrue($custody->verifySuccessionAnchor($succession, $message));
        self::assertFalse($custody->verifyCheckpoint($prune, $message));
        self::assertFalse($custody->verifyPruneAuthorization($succession, $message));
    }

    #[Test]
    public function legacy_checkpoint_custody_preserves_the_existing_wire_format_explicitly(): void
    {
        $key = hash('sha256', 'legacy-audit-key', true);
        $custody = new AuditCheckpointCustody(legacyKey: $key);
        $checkpointHash = str_repeat('e', 64);
        $signature = $custody->sealCheckpoint($checkpointHash);

        self::assertSame(
            'hmac-sha256.hkdf-v1:' . hash_hmac('sha256', $checkpointHash, $key),
            $signature,
        );
        self::assertTrue($custody->verifyCheckpoint($signature, $checkpointHash));
    }

    #[Test]
    public function legacy_compatibility_preserves_non_empty_hmac_key_shapes(): void
    {
        $checkpointHash = str_repeat('1', 64);
        foreach (['short-operator-key', str_repeat('a', 64)] as $key) {
            $custody = new AuditCheckpointCustody(legacyKey: $key);

            self::assertSame(
                'hmac-sha256.hkdf-v1:' . hash_hmac('sha256', $checkpointHash, $key),
                $custody->sealCheckpoint($checkpointHash),
            );
        }
    }

    #[Test]
    public function an_empty_legacy_hmac_key_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditCheckpointCustody(legacyKey: '');
    }

    #[Test]
    public function versioned_envelopes_never_fall_back_to_a_configured_legacy_key(): void
    {
        $message = str_repeat('f', 64);
        $signature = (new AuditCheckpointCustody($this->keyring(1)))->sealCheckpoint($message);
        $legacyOnly = new AuditCheckpointCustody(legacyKey: hash('sha256', 'legacy-only', true));

        self::assertFalse($legacyOnly->verifyCheckpoint($signature, $message));
    }

    #[Test]
    public function custody_diagnostics_and_serialization_cannot_export_key_material(): void
    {
        $custody = new AuditCheckpointCustody(
            $this->keyring(2, [1]),
            hash('sha256', 'legacy-bridge', true),
        );
        $diagnostic = print_r($custody, true);

        self::assertStringContainsString('[NON_EXPORTING]', $diagnostic);
        self::assertStringContainsString('[REDACTED]', $diagnostic);
        self::assertStringNotContainsString('legacy-bridge', $diagnostic);

        $this->expectException(\LogicException::class);
        serialize($custody);
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
        $resolver->registerProvider(new AuditCustodySyntheticMasterProvider());
        $resolver->allow(
            'audit-custody-synthetic-master',
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
            'audit-custody-synthetic-master',
            'master-v' . $version,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
        );
    }
}

final class AuditCustodySyntheticMasterProvider implements SecretProviderInterface
{
    public function id(): string
    {
        return 'audit-custody-synthetic-master';
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
