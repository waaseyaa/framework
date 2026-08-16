<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\StorageInterface;

/**
 * Retained read adapter for the pre-CFG-03 public surface.
 *
 * Per-entry StorageInterface mutation cannot authenticate a complete authored
 * bundle and therefore always refuses. New activation callers must hand a
 * verified strict-v1 candidate directly to CFG-02.
 *
 * @api
 */
final class TransactionalConfigurationStorage implements StorageInterface
{
    /** @var array<string, self> */
    private array $collections = [];
    public function __construct(
        private readonly StorageInterface $reader,
        private readonly ConfigurationActivatorInterface $activator,
        private readonly ConfigurationActiveToken $observedToken,
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
            $this->activator,
            $this->observedToken,
            $collection,
        );
    }

    private function mutationUnavailable(): ConfigurationAuthorityUnavailableException
    {
        return new ConfigurationAuthorityUnavailableException(
            'Legacy per-entry configuration mutation cannot authenticate a complete CFG-03 bundle and is unavailable.',
        );
    }
}
