<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Cache;

use Waaseyaa\Config\Config;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Config\Storage\MemoryStorage;

final class CachedConfigFactory implements ConfigFactoryInterface
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    private bool $cacheLoaded = false;

    public function __construct(
        private readonly ConfigFactoryInterface $inner,
        private readonly string $cachePath,
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
            $this->cache = require $path;
        }
    }
}
