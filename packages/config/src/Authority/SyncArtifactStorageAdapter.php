<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

use Waaseyaa\Config\Storage\FileStorage;
use Waaseyaa\Config\StorageInterface;

/** Legacy StorageInterface view of the resolver-selected sync artifact. */
final class SyncArtifactStorageAdapter implements StorageInterface
{
    private FileStorage $storage;

    public function __construct(ConfigurationAuthorityContext $context)
    {
        $this->storage = new FileStorage($context->syncPath);
    }

    public function exists(string $name): bool
    {
        return $this->storage->exists($name);
    }
    public function read(string $name): array|false
    {
        return $this->storage->read($name);
    }
    public function readMultiple(array $names): array
    {
        return $this->storage->readMultiple($names);
    }
    public function write(string $name, array $data): bool
    {
        return $this->storage->write($name, $data);
    }
    public function delete(string $name): bool
    {
        return $this->storage->delete($name);
    }
    public function rename(string $name, string $newName): bool
    {
        return $this->storage->rename($name, $newName);
    }
    public function listAll(string $prefix = ''): array
    {
        return $this->storage->listAll($prefix);
    }
    public function deleteAll(string $prefix = ''): bool
    {
        return $this->storage->deleteAll($prefix);
    }
    public function createCollection(string $collection): static
    {
        throw new \LogicException('Legacy sync collections are not part of configuration.authority.v1.');
    }
    public function getCollectionName(): string
    {
        return '';
    }
    public function getAllCollectionNames(): array
    {
        return [];
    }
}
