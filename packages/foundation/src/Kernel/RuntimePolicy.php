<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

/**
 * The environment and debug policy resolved from the kernel bootstrap inputs.
 *
 * This is bootstrap configuration, not governed/syncable application content.
 */
final readonly class RuntimePolicy
{
    private const array DEVELOPMENT_ENVIRONMENTS = ['dev', 'development', 'local', 'testing'];

    public function __construct(
        public string $environment,
        public bool $debug,
    ) {}

    /** @param array<string, mixed> $config */
    public static function resolve(array $config): self
    {
        if (array_key_exists('environment', $config)) {
            $configuredEnvironment = $config['environment'];
            $environment = is_string($configuredEnvironment) ? $configuredEnvironment : 'production';
        } else {
            $processEnvironment = getenv('APP_ENV');
            $environment = is_string($processEnvironment) && $processEnvironment !== '' && $processEnvironment !== '0'
                ? $processEnvironment
                : 'production';
        }

        $debug = getenv('APP_DEBUG');
        if (!is_string($debug) || $debug === '') {
            $debug = $config['debug'] ?? false;
        }

        return new self(
            environment: $environment,
            debug: filter_var($debug, FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function isDevelopment(): bool
    {
        return self::isDevelopmentEnvironment($this->environment);
    }

    public function isProductionLike(): bool
    {
        return !$this->isDevelopment();
    }

    /** Canonical normalized classifier for Foundation-dependent package policy. */
    public static function isDevelopmentEnvironment(string $environment): bool
    {
        return in_array(strtolower(trim($environment)), self::DEVELOPMENT_ENVIRONMENTS, true);
    }

    /**
     * Classify only an explicitly configured environment.
     *
     * Security-sensitive provider fallbacks use this form when a missing
     * configured profile must remain production-like rather than inheriting a
     * mutable process environment.
     *
     * @param array<string, mixed> $config
     */
    public static function isExplicitDevelopment(array $config): bool
    {
        $environment = $config['environment'] ?? null;

        return is_string($environment) && self::isDevelopmentEnvironment($environment);
    }
}
