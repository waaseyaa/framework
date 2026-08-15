<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** Versioned non-exporting custody for application-master encryption. @api */
final class ApplicationMasterKeyring
{
    public const string PACKAGE = 'waaseyaa/foundation';
    public const string MASTER_PURPOSE = 'waaseyaa.application.master.v1';
    public const int MAX_LEGACY_VERSIONS = 32;

    /** @var array<int, SecretHandle> */
    private array $handles;

    private function __construct(
        private readonly int $activeVersion,
        array $handles,
        private readonly ApplicationMasterPurposeRegistry $purposes,
        /** @var list<int> */
        private readonly array $failedVersions,
    ) {
        $this->handles = $handles;
    }

    /**
     * @param array<array-key, mixed> $legacyReferences
     */
    public static function fromReferences(
        SecretResolverRegistry $resolver,
        int $activeVersion,
        SecretReference $activeReference,
        array $legacyReferences,
        ApplicationMasterPurposeRegistry $purposes,
    ): self {
        if (!$resolver->isFrozen()) {
            throw new \LogicException('The secret resolver must be frozen before keyring construction.');
        }
        if (!$purposes->isFrozen()) {
            throw new \LogicException('Application-master purposes must be frozen before keyring construction.');
        }
        if ($activeVersion < 1) {
            throw new \InvalidArgumentException('The active application-master version must be positive.');
        }
        if (count($legacyReferences) > self::MAX_LEGACY_VERSIONS) {
            throw new \InvalidArgumentException('The application-master legacy roster exceeds its bounded limit.');
        }

        self::assertReference($activeReference);
        $references = [$activeVersion => $activeReference];
        $fingerprints = [$activeReference->fingerprint() => true];
        foreach ($legacyReferences as $version => $reference) {
            if (!is_int($version) || $version < 1 || $version >= $activeVersion) {
                throw new \InvalidArgumentException('Legacy application-master versions must be positive and lower than active.');
            }
            if (!$reference instanceof SecretReference) {
                throw new \InvalidArgumentException('Legacy application-master entries must be secret references.');
            }
            self::assertReference($reference);
            if (isset($fingerprints[$reference->fingerprint()])) {
                throw new \InvalidArgumentException('Application-master versions require distinct external references.');
            }
            $fingerprints[$reference->fingerprint()] = true;
            $references[$version] = $reference;
        }
        ksort($references, SORT_NUMERIC);

        return self::create($resolver, $activeVersion, $references, $purposes, []);
    }

    /**
     * Compose an exercised rollback: the predecessor is active, lower legacy
     * versions remain readable, and exactly one higher failed successor is
     * read-only and permanently distinct.
     *
     * @param array<array-key, mixed> $legacyReferences
     */
    public static function fromRollbackReferences(
        SecretResolverRegistry $resolver,
        int $activeVersion,
        SecretReference $activeReference,
        array $legacyReferences,
        int $failedVersion,
        SecretReference $failedReference,
        ApplicationMasterPurposeRegistry $purposes,
    ): self {
        if (!$resolver->isFrozen()) {
            throw new \LogicException('The secret resolver must be frozen before keyring construction.');
        }
        if (!$purposes->isFrozen()) {
            throw new \LogicException('Application-master purposes must be frozen before keyring construction.');
        }
        if ($activeVersion < 1 || $failedVersion <= $activeVersion) {
            throw new \InvalidArgumentException('Rollback application-master topology requires one higher failed successor.');
        }
        if (count($legacyReferences) + 1 > self::MAX_LEGACY_VERSIONS) {
            throw new \InvalidArgumentException('The application-master read-only roster exceeds its bounded limit.');
        }

        self::assertReference($activeReference);
        self::assertReference($failedReference);
        $references = [$activeVersion => $activeReference];
        $fingerprints = [$activeReference->fingerprint() => true];
        foreach ($legacyReferences as $version => $reference) {
            if (!is_int($version) || $version < 1 || $version >= $activeVersion) {
                throw new \InvalidArgumentException('Rollback legacy application-master versions must be positive and lower than active.');
            }
            if (!$reference instanceof SecretReference) {
                throw new \InvalidArgumentException('Rollback legacy application-master entries must be secret references.');
            }
            self::assertReference($reference);
            if (isset($fingerprints[$reference->fingerprint()])) {
                throw new \InvalidArgumentException('Application-master versions require distinct external references.');
            }
            $fingerprints[$reference->fingerprint()] = true;
            $references[$version] = $reference;
        }
        if (isset($fingerprints[$failedReference->fingerprint()]) || isset($references[$failedVersion])) {
            throw new \InvalidArgumentException('The failed application-master successor requires a distinct version and reference.');
        }
        $references[$failedVersion] = $failedReference;
        ksort($references, SORT_NUMERIC);

        return self::create($resolver, $activeVersion, $references, $purposes, [$failedVersion]);
    }

    public static function registerResolverConsumers(SecretResolverRegistry $registry): void
    {
        $registry->registerConsumer(self::PACKAGE, ApplicationMasterSealOperation::class);
        $registry->registerConsumer(self::PACKAGE, ApplicationMasterOpenOperation::class);
        $registry->registerConsumer(self::PACKAGE, ApplicationMasterAuthenticateOperation::class);
        $registry->registerConsumer(self::PACKAGE, ApplicationMasterVerifyOperation::class);
        $registry->registerConsumer(self::PACKAGE, ApplicationMasterLookupOperation::class);
    }

    public function activeVersion(): int
    {
        return $this->activeVersion;
    }

    /** @return list<int> */
    public function readableVersions(): array
    {
        return array_keys($this->handles);
    }

    public function purposeRegistryChecksum(): string
    {
        return $this->purposes->checksum();
    }

    public function seal(string $purpose, string $recordIdentity, int $schemaVersion, #[\SensitiveParameter] string $plaintext): ApplicationMasterEnvelope
    {
        $policy = $this->purposes->policy($purpose);
        if ($policy->strategy !== ApplicationMasterPurposeStrategy::ReencryptCiphertext) {
            throw new \InvalidArgumentException('Only re-encrypt ciphertext purposes may create application-master envelopes.');
        }
        ApplicationMasterEnvelope::encodeAssociatedData(
            $this->activeVersion,
            $purpose,
            $recordIdentity,
            $schemaVersion,
        );

        return $this->handles[$this->activeVersion]->consume(new ApplicationMasterSealOperation(
            $this->activeVersion,
            $purpose,
            $recordIdentity,
            $schemaVersion,
            $plaintext,
        ));
    }

    public function open(ApplicationMasterEnvelope $envelope): string
    {
        try {
            $policy = $this->purposes->policy($envelope->purpose);
        } catch (\InvalidArgumentException) {
            throw new \RuntimeException('Application-master envelope purpose is not registered.');
        }
        if ($policy->strategy !== ApplicationMasterPurposeStrategy::ReencryptCiphertext) {
            throw new \RuntimeException('Application-master envelope purpose is not ciphertext-bearing.');
        }
        $handle = $this->handles[$envelope->masterVersion] ?? null;
        if ($handle === null) {
            throw new \RuntimeException('Application-master envelope version is not declared readable.');
        }

        return $handle->consume(new ApplicationMasterOpenOperation($envelope));
    }

    public function authenticate(string $purpose, #[\SensitiveParameter] string $message): ApplicationMasterAuthenticationTag
    {
        $policy = $this->purposes->policy($purpose);
        if ($policy->strategy === ApplicationMasterPurposeStrategy::ReencryptCiphertext) {
            throw new \InvalidArgumentException('Ciphertext purposes cannot create application-master authentication tags.');
        }

        return $this->handles[$this->activeVersion]->consume(new ApplicationMasterAuthenticateOperation(
            $this->activeVersion,
            $purpose,
            $message,
        ));
    }

    public function verify(ApplicationMasterAuthenticationTag $tag, #[\SensitiveParameter] string $message): bool
    {
        $policy = $this->purposes->policy($tag->purpose);
        if ($policy->strategy === ApplicationMasterPurposeStrategy::ReencryptCiphertext) {
            throw new \InvalidArgumentException('Ciphertext purposes cannot verify application-master authentication tags.');
        }
        $handle = $this->handles[$tag->masterVersion] ?? null;
        if ($handle === null) {
            throw new \RuntimeException('Application-master authentication version is not declared readable.');
        }

        return $handle->consume(new ApplicationMasterVerifyOperation($tag, $message));
    }

    /** @return list<ApplicationMasterAuthenticationTag> */
    public function lookupCandidates(string $purpose, #[\SensitiveParameter] string $lookupValue): array
    {
        $policy = $this->purposes->policy($purpose);
        if ($policy->strategy !== ApplicationMasterPurposeStrategy::RecomputeLookupIndex) {
            throw new \InvalidArgumentException('Only lookup-index purposes may create readable-version lookup candidates.');
        }

        $candidates = [];
        foreach ($this->handles as $version => $handle) {
            $candidates[] = $handle->consume(new ApplicationMasterLookupOperation(
                $version,
                $purpose,
                $lookupValue,
            ));
        }

        return $candidates;
    }

    /** @return array{keyring: string, active_version: int, legacy_versions: list<int>, failed_versions: list<int>, purpose_registry_checksum: string} */
    public function __debugInfo(): array
    {
        $versions = array_keys($this->handles);

        return [
            'keyring' => '[NON_EXPORTING]',
            'active_version' => $this->activeVersion,
            'legacy_versions' => array_values(array_filter(
                $versions,
                fn(int $version): bool => $version !== $this->activeVersion
                    && !in_array($version, $this->failedVersions, true),
            )),
            'failed_versions' => $this->failedVersions,
            'purpose_registry_checksum' => $this->purposes->checksum(),
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Application-master keyrings cannot be serialized.');
    }

    private function __clone() {}

    private static function assertReference(SecretReference $reference): void
    {
        if ($reference->secretClass() !== SecretClass::ApplicationMaster
            || $reference->purpose() !== self::MASTER_PURPOSE) {
            throw new \InvalidArgumentException('Application-master references require the exact class and custody purpose.');
        }
    }

    /**
     * @param array<int, SecretReference> $references
     * @param list<int> $failedVersions
     */
    private static function create(
        SecretResolverRegistry $resolver,
        int $activeVersion,
        array $references,
        ApplicationMasterPurposeRegistry $purposes,
        array $failedVersions,
    ): self {
        $handles = [];
        foreach ($references as $version => $reference) {
            $allowedConsumers = $version === $activeVersion
                ? [
                    ApplicationMasterSealOperation::class,
                    ApplicationMasterOpenOperation::class,
                    ApplicationMasterAuthenticateOperation::class,
                    ApplicationMasterVerifyOperation::class,
                    ApplicationMasterLookupOperation::class,
                ]
                : [
                    ApplicationMasterOpenOperation::class,
                    ApplicationMasterVerifyOperation::class,
                    ApplicationMasterLookupOperation::class,
                ];
            $handles[$version] = SecretHandle::fromReference(
                $resolver,
                $reference,
                self::PACKAGE,
                $allowedConsumers,
            );
        }

        return new self($activeVersion, $handles, $purposes, $failedVersions);
    }
}
