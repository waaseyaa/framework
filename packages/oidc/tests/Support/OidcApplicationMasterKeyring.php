<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Support;

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

final class OidcApplicationMasterKeyring
{
    /** @param list<int> $legacyVersions */
    public static function create(int $activeVersion, array $legacyVersions = []): ApplicationMasterKeyring
    {
        [$resolver, $purposes] = self::composition();

        $legacyReferences = [];
        foreach ($legacyVersions as $version) {
            $legacyReferences[$version] = self::reference($version);
        }

        return ApplicationMasterKeyring::fromReferences(
            $resolver,
            $activeVersion,
            self::reference($activeVersion),
            $legacyReferences,
            $purposes,
        );
    }

    /** @param list<int> $legacyVersions */
    public static function rollback(int $activeVersion, int $failedVersion, array $legacyVersions = []): ApplicationMasterKeyring
    {
        [$resolver, $purposes] = self::composition();
        $legacyReferences = [];
        foreach ($legacyVersions as $version) {
            $legacyReferences[$version] = self::reference($version);
        }

        return ApplicationMasterKeyring::fromRollbackReferences(
            $resolver,
            $activeVersion,
            self::reference($activeVersion),
            $legacyReferences,
            $failedVersion,
            self::reference($failedVersion),
            $purposes,
        );
    }

    /** @return array{SecretResolverRegistry, ApplicationMasterPurposeRegistry} */
    private static function composition(): array
    {
        $purposes = new ApplicationMasterPurposeRegistry();
        foreach (self::policies() as $policy) {
            $purposes->register($policy);
        }
        $purposes->freeze();

        $resolver = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $resolver->registerProvider(new OidcSyntheticMasterProvider());
        $resolver->allow(
            'oidc-synthetic-master',
            ApplicationMasterKeyring::PACKAGE,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
            ['testing'],
        );
        ApplicationMasterKeyring::registerResolverConsumers($resolver);
        $resolver->freeze();

        return [$resolver, $purposes];
    }

    /** @return list<ApplicationMasterPurposePolicy> */
    private static function policies(): array
    {
        return [
            new ApplicationMasterPurposePolicy(
                ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
                'waaseyaa/oidc',
                ApplicationMasterPurposeStrategy::ReencryptCiphertext,
                7_776_000,
                7_863_000,
                'oidc-signing-key-v1',
                'restore-predecessor-ciphertext',
            ),
            new ApplicationMasterPurposePolicy(
                ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
                'waaseyaa/oidc',
                ApplicationMasterPurposeStrategy::ReencryptCiphertext,
                3_600,
                3_900,
                'oidc-access-token-v1',
                'restore-predecessor-ciphertext-and-index',
            ),
            new ApplicationMasterPurposePolicy(
                ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
                'waaseyaa/oidc',
                ApplicationMasterPurposeStrategy::RecomputeLookupIndex,
                3_600,
                3_900,
                'oidc-access-token-v1',
                'restore-predecessor-ciphertext-and-index',
            ),
            new ApplicationMasterPurposePolicy(
                ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION,
                'waaseyaa/oidc',
                ApplicationMasterPurposeStrategy::ReencryptCiphertext,
                7_776_000,
                7_776_300,
                'oidc-refresh-token-v1',
                'restore-predecessor-ciphertext-and-index',
            ),
            new ApplicationMasterPurposePolicy(
                ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP,
                'waaseyaa/oidc',
                ApplicationMasterPurposeStrategy::RecomputeLookupIndex,
                7_776_000,
                7_776_300,
                'oidc-refresh-token-v1',
                'restore-predecessor-ciphertext-and-index',
            ),
        ];
    }

    private static function reference(int $version): SecretReference
    {
        return SecretReference::create(
            'oidc-synthetic-master',
            'master-v' . $version,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
        );
    }
}

final class OidcSyntheticMasterProvider implements SecretProviderInterface
{
    public function id(): string
    {
        return 'oidc-synthetic-master';
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
