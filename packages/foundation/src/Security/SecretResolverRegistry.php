<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;

/**
 * Frozen kernel-scoped authority for governed secret resolution.
 *
 * Package declarations provide composition integrity rather than a same-process
 * sandbox. Resolution is unavailable until providers and exact policy tuples
 * have been registered and the registry is frozen.
 *
 * @api
 */
final class SecretResolverRegistry
{
    /** @var array<string, SecretProviderInterface> */
    private array $providers = [];

    /** @var array<string, true> */
    private array $policies = [];

    private bool $frozen = false;

    private readonly string $environment;

    public function __construct(
        private readonly RedactorProcessor $sinkSanitizer,
        string $environment,
    ) {
        $environment = strtolower(trim($environment));
        self::assertStableIdentifier($environment, 'environment');
        $this->environment = $environment;
    }

    public function registerProvider(SecretProviderInterface $provider): void
    {
        $this->assertMutable();
        $id = $provider->id();
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id)) {
            throw new \InvalidArgumentException('Secret provider IDs must be stable lowercase identifiers.');
        }
        if (isset($this->providers[$id])) {
            throw new \InvalidArgumentException('Secret provider IDs must be unique.');
        }
        $this->providers[$id] = $provider;
    }

    /** @param list<string> $environments */
    public function allow(
        string $provider,
        string $package,
        SecretClass $secretClass,
        string $purpose,
        array $environments,
    ): void {
        $this->assertMutable();
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $provider)) {
            throw new \InvalidArgumentException('Secret provider IDs must be stable lowercase identifiers.');
        }
        if (!preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#D', $package)) {
            throw new \InvalidArgumentException('Secret policy packages must use vendor/package identifiers.');
        }
        if (!preg_match('/^waaseyaa\.[a-z0-9.-]+\.v[1-9][0-9]*$/D', $purpose)) {
            throw new \InvalidArgumentException('Secret policy purposes must be versioned Waaseyaa identifiers.');
        }
        if ($environments === []) {
            throw new \InvalidArgumentException('Secret policies require at least one environment.');
        }
        foreach ($environments as $environment) {
            self::assertStableIdentifier($environment, 'environment');
            $this->policies[$this->policyKey($provider, $package, $secretClass, $purpose, $environment)] = true;
        }
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function resolve(SecretReference $reference, string $package): SensitiveValue
    {
        if (!$this->frozen) {
            throw new SecretResolutionException(SecretResolutionCode::RegistryNotFrozen, $reference->fingerprint());
        }
        $policy = $this->policyKey(
            $reference->provider(),
            $package,
            $reference->secretClass(),
            $reference->purpose(),
            $this->environment,
        );
        if (!isset($this->policies[$policy])) {
            throw new SecretResolutionException(SecretResolutionCode::PolicyDenied, $reference->fingerprint());
        }
        $provider = $this->providers[$reference->provider()] ?? null;
        if ($provider === null) {
            throw new SecretResolutionException(SecretResolutionCode::ProviderUnknown, $reference->fingerprint());
        }

        try {
            $value = $provider->resolve($reference);
        } catch (\Throwable) {
            throw new SecretResolutionException(SecretResolutionCode::ProviderFailure, $reference->fingerprint());
        }
        if ($value->secretClass !== $reference->secretClass()) {
            throw new SecretResolutionException(SecretResolutionCode::ClassMismatch, $reference->fingerprint());
        }

        $value->registerWith($this->sinkSanitizer);

        return $value;
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new \LogicException('The secret resolver registry is frozen.');
        }
    }

    private static function assertStableIdentifier(string $value, string $label): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $value)) {
            throw new \InvalidArgumentException(sprintf('Secret policy %s must be a stable lowercase identifier.', $label));
        }
    }

    private function policyKey(
        string $provider,
        string $package,
        SecretClass $secretClass,
        string $purpose,
        string $environment,
    ): string {
        return implode("\0", [$provider, $package, $secretClass->value, $purpose, $environment]);
    }
}
