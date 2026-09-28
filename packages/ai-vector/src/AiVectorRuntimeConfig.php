<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Foundation\Sovereignty\SovereigntyConfig;

/**
 * Parses the explicit activation and storage-backend contract for ai-vector.
 *
 * Installation alone never activates the capability. The portable database
 * backend stores JSON vectors through DatabaseInterface and is the only
 * backend currently implemented by this package.
 */
final readonly class AiVectorRuntimeConfig
{
    public const string DATABASE_BACKEND = 'database';

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $ai = is_array($config['ai'] ?? null) ? $config['ai'] : [];
        $enabled = ($ai['vector_enabled'] ?? false) === true;
        if (!$enabled) {
            return new self(false, self::DATABASE_BACKEND);
        }

        $backend = array_key_exists('vector_backend', $ai)
            ? $ai['vector_backend']
            : SovereigntyConfig::fromArray($config)->get('vector_store');

        if (!is_string($backend)) {
            throw new UnsupportedVectorBackendException($backend);
        }

        return new self($enabled, $backend);
    }

    public function __construct(
        public bool $enabled,
        public string $backend,
    ) {}

    public function assertSupportedBackend(): void
    {
        if ($this->backend === self::DATABASE_BACKEND) {
            return;
        }

        throw new UnsupportedVectorBackendException($this->backend);
    }
}
