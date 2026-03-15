<?php

declare(strict_types=1);

namespace Waaseyaa\Config;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ConfigFactory implements ConfigFactoryInterface
{
    /**
     * In-memory cache of loaded immutable configs.
     *
     * @var array<string, ConfigInterface>
     */
    private array $cache = [];

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function get(string $name): ConfigInterface
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $data = $this->storage->read($name);
        $isNew = $data === false;

        $config = new Config(
            name: $name,
            storage: $this->storage,
            data: $isNew ? [] : $data,
            immutable: true,
            isNew: $isNew,
        );

        $this->cache[$name] = $config;

        return $config;
    }

    public function getEditable(string $name): ConfigInterface
    {
        $data = $this->storage->read($name);
        $isNew = $data === false;

        return new Config(
            name: $name,
            storage: new EventAwareStorage($this->storage, $this->eventDispatcher, $this),
            data: $isNew ? [] : $data,
            immutable: false,
            isNew: $isNew,
        );
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
        $this->storage->rename($oldName, $newName);

        unset($this->cache[$oldName], $this->cache[$newName]);

        return $this;
    }

    public function listAll(string $prefix = ''): array
    {
        return $this->storage->listAll($prefix);
    }

    /**
     * Invalidate the cache for a given config name.
     *
     * @internal Used by EventAwareStorage to invalidate cache on save/delete.
     */
    public function invalidateCache(string $name): void
    {
        unset($this->cache[$name]);
    }
}
