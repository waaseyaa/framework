<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

use Waaseyaa\Config\StorageInterface;

/** Production read adapter while CFG-03/04 mutation authority remains unbound. @api */
final class ReadOnlyActiveConfigurationStorage implements StorageInterface
{
    /** @var array<string, self> */
    private array $collections = [];

    public function __construct(
        private readonly StorageInterface $reader,
        private readonly string $collection = '',
    ) {}

    public function exists(string $name): bool
    {
        return $this->reader->exists($name);
    }

    public function read(string $name): array|false
    {
        return $this->reader->read($name);
    }

    public function readMultiple(array $names): array
    {
        return $this->reader->readMultiple($names);
    }

    public function listAll(string $prefix = ''): array
    {
        return $this->reader->listAll($prefix);
    }

    public function getCollectionName(): string
    {
        return $this->collection;
    }

    public function getAllCollectionNames(): array
    {
        return $this->reader->getAllCollectionNames();
    }

    public function write(string $name, array $data): bool
    {
        throw $this->mutationUnavailable();
    }

    public function delete(string $name): bool
    {
        throw $this->mutationUnavailable();
    }

    public function rename(string $name, string $newName): bool
    {
        throw $this->mutationUnavailable();
    }

    public function deleteAll(string $prefix = ''): bool
    {
        throw $this->mutationUnavailable();
    }

    public function createCollection(string $collection): static
    {
        return $this->collections[$collection] ??= new self(
            $this->reader->createCollection($collection),
            $collection,
        );
    }

    private function mutationUnavailable(): ConfigurationAuthorityUnavailableException
    {
        return new ConfigurationAuthorityUnavailableException(
            'Production editable configuration is unavailable until a verified CFG-03 manifest and CFG-04 signing authority are bound.',
        );
    }
}
