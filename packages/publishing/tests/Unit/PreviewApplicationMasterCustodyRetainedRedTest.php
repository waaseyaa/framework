<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Publishing\Preview\PreviewLinkService;
use Waaseyaa\Publishing\PublishingServiceProvider;
use Waaseyaa\Publishing\Rekey\PublishingPreviewRekeyAdapter;

/** Retained-red proof for the real publishing preview purpose owner. */
final class PreviewApplicationMasterCustodyRetainedRedTest extends TestCase
{
    #[Test]
    public function preview_signatures_write_active_and_verify_only_declared_versions(): void
    {
        $predecessor = PreviewLinkService::fromApplicationMasterKeyring(
            $this->keyring(1),
            clock: static fn(): int => 1_000_000,
        );
        $old = $predecessor->issueRevision('node', '42', 7, 600);
        $rotated = PreviewLinkService::fromApplicationMasterKeyring(
            $this->keyring(2, [1]),
            clock: static fn(): int => 1_000_000,
        );
        $new = $rotated->issueRevision('node', '42', 8, 600);

        self::assertStringStartsWith('hmac-sha256.application-master.preview.v1:1:', $old->signature);
        self::assertStringStartsWith('hmac-sha256.application-master.preview.v1:2:', $new->signature);
        self::assertTrue($rotated->verifyRevision('node', '42', 7, $old->expiresAt, $old->signature));
        self::assertTrue($rotated->verifyRevision('node', '42', 8, $new->expiresAt, $new->signature));
        self::assertFalse($rotated->verifyRevision(
            'node',
            '42',
            8,
            $new->expiresAt,
            str_replace(':2:', ':99:', $new->signature),
        ));
    }

    #[Test]
    public function application_master_preview_requires_an_explicit_legacy_bridge(): void
    {
        $legacy = new PreviewLinkService('legacy-preview-key', static fn(): int => 1_000_000);
        $token = $legacy->issue('node', '42', 600);
        $strict = PreviewLinkService::fromApplicationMasterKeyring(
            $this->keyring(2, [1]),
            clock: static fn(): int => 1_000_000,
        );
        $compatibility = PreviewLinkService::fromApplicationMasterKeyring(
            $this->keyring(2, [1]),
            'legacy-preview-key',
            static fn(): int => 1_000_000,
        );

        self::assertFalse($strict->verify('node', '42', $token->expiresAt, $token->signature));
        self::assertTrue($compatibility->verify('node', '42', $token->expiresAt, $token->signature));
    }

    #[Test]
    public function provider_binds_runtime_custody_and_the_exact_zero_row_policy(): void
    {
        $database = DBALDatabase::createSqlite();
        $keyring = $this->keyring(2, [1]);
        $provider = new PublishingServiceProvider();
        $provider->setKernelContext('', [], []);
        $provider->setKernelServices(new class ($database, $keyring) implements KernelServicesInterface {
            public function __construct(
                private readonly DatabaseInterface $database,
                private readonly ApplicationMasterKeyring $keyring,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    DatabaseInterface::class => $this->database,
                    ApplicationMasterKeyring::class => $this->keyring,
                    default => null,
                };
            }
        });
        $provider->register();

        $token = $provider->resolve(PreviewLinkService::class)->issue('node', '42', 600);
        $contributions = iterator_to_array($provider->applicationMasterRekeyContributions(), false);

        self::assertStringStartsWith('hmac-sha256.application-master.preview.v1:2:', $token->signature);
        self::assertCount(1, $contributions);
        self::assertSame($database, $contributions[0]->adapter()->databaseAuthority());
        self::assertSame(PublishingPreviewRekeyAdapter::ID, $contributions[0]->adapter()->id());
        self::assertSame([ApplicationSecret::PURPOSE_PUBLISHING_PREVIEW_HMAC], $contributions[0]->adapter()->purposeIds());
        self::assertSame([
            'id' => ApplicationSecret::PURPOSE_PUBLISHING_PREVIEW_HMAC,
            'owner_package' => 'waaseyaa/publishing',
            'strategy' => ApplicationMasterPurposeStrategy::EphemeralNoPersistence->value,
            'maximum_lifetime_seconds' => PreviewLinkService::DEFAULT_TTL_SECONDS,
            'retention_seconds' => PreviewLinkService::DEFAULT_TTL_SECONDS,
            'adapter_id' => PublishingPreviewRekeyAdapter::ID,
            'rollback_behavior' => 'verify-declared-version-until-expiry',
        ], $contributions[0]->policies()[0]->canonicalRecord());
    }

    /** @param list<int> $legacyVersions */
    private function keyring(int $activeVersion, array $legacyVersions = []): ApplicationMasterKeyring
    {
        $purposes = new ApplicationMasterPurposeRegistry();
        $purposes->register(new ApplicationMasterPurposePolicy(
            ApplicationSecret::PURPOSE_PUBLISHING_PREVIEW_HMAC,
            'waaseyaa/publishing',
            ApplicationMasterPurposeStrategy::EphemeralNoPersistence,
            PreviewLinkService::DEFAULT_TTL_SECONDS,
            PreviewLinkService::DEFAULT_TTL_SECONDS,
            PublishingPreviewRekeyAdapter::ID,
            'verify-declared-version-until-expiry',
        ));
        $purposes->freeze();
        $resolver = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $resolver->registerProvider(new PublishingPreviewSyntheticMasterProvider());
        $resolver->allow(
            'publishing-preview-synthetic-master',
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
            'publishing-preview-synthetic-master',
            'master-v' . $version,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
        );
    }
}

final class PublishingPreviewSyntheticMasterProvider implements SecretProviderInterface
{
    public function id(): string
    {
        return 'publishing-preview-synthetic-master';
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
