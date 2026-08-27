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
        return in_array(strtolower(trim($this->environment)), self::DEVELOPMENT_ENVIRONMENTS, true);
    }

    public function isProductionLike(): bool
    {
        return !$this->isDevelopment();
    }
}
