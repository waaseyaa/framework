<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Cache;

use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Config;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Config\Storage\MemoryStorage;

/**
 * @api
 */
final class CachedConfigFactory implements ConfigFactoryInterface
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    private bool $cacheLoaded = false;

    public function __construct(
        private readonly ConfigFactoryInterface $inner,
        private readonly string $cachePath,
        private readonly ?ConfigurationAuthorityContext $authorityContext = null,
    ) {}

    public function get(string $name): ConfigInterface
    {
        $this->ensureCacheLoaded();

        if ($this->cache !== null && array_key_exists($name, $this->cache)) {
            return new Config(
                name: $name,
                storage: new MemoryStorage(),
                data: $this->cache[$name],
                immutable: true,
                isNew: false,
            );
        }

        return $this->inner->get($name);
    }

    public function getEditable(string $name): ConfigInterface
    {
        // Editable always goes through the inner factory
        return $this->inner->getEditable($name);
    }

    public function loadMultiple(array $names): array
    {
        $configs = [];
        foreach ($names as $name) {
            $configs[$name] = $this->get($name);
        }
        return $configs;
    }

    public function rename(string $oldName, string $newName): static
    {
        $this->inner->rename($oldName, $newName);
        return $this;
    }

    public function listAll(string $prefix = ''): array
    {
        return $this->inner->listAll($prefix);
    }

    private function ensureCacheLoaded(): void
    {
        if ($this->cacheLoaded) {
            return;
        }

        $this->cacheLoaded = true;
        $path = $this->cachePath;

        if (is_file($path)) {
            try {
                $data = require $path;
                if (is_array($data)) {
                    $this->cache = $this->validatedPayload($data);
                }
            } catch (\Throwable) {
                // Corrupt cache — fall through to inner factory
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<string, mixed>>|null
     */
    private function validatedPayload(array $payload): ?array
    {
        if (!array_key_exists('_waaseyaa_config_cache_v1', $payload)) {
            return $this->authorityContext === null ? $payload : null;
        }
        if ($this->authorityContext === null) {
            return null;
        }
        $metadata = $payload['_waaseyaa_config_cache_v1'];
        $config = $payload['config'] ?? null;
        if (!is_array($metadata) || !is_array($config)) {
            return null;
        }
        if (
            ($metadata['authority_id'] ?? null) !== $this->authorityContext->authorityId
            || ($metadata['generation_id'] ?? null) !== $this->authorityContext->activeGenerationId
            || ($metadata['activation_sequence'] ?? null) !== $this->authorityContext->activationSequence
        ) {
            return null;
        }
        foreach ($config as $name => $values) {
            if (!is_string($name) || !is_array($values)) {
                return null;
            }
        }

        return $config;
    }
}
