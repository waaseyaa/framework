<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\StorageInterface;
use Waaseyaa\Config\Sync\ConfigSyncFile;

/** Legacy StorageInterface mutation adapter over one immutable successor-generation CAS. */
final class TransactionalConfigurationStorage implements StorageInterface
{
    /** @var array<string, self> */
    private array $collections = [];
    /** @var array<string, ConfigSyncFile>|null */
    private ?array $observedFiles = null;

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
        $ref = $this->rootRef($name);
        $existing = $this->files()[$ref] ?? null;
        [$entityType, $entityId] = explode('.', $ref, 2);
        ksort($data, SORT_STRING);
        $file = new ConfigSyncFile(
            $entityType,
            $entityId,
            $existing === null ? ConfigSyncFile::deterministicUuid($entityType, $entityId) : $existing->uuid,
            $existing === null ? [] : $existing->dependencies,
            $existing === null ? 'en' : $existing->langcode,
            $data,
        );
        $this->activator->activate(new ConfigurationActivationRequest(
            $this->requestId('save', $ref),
            $this->observedToken,
            [$file],
            expectedEntryHashes: $existing === null ? [] : [$ref => $existing->contentHash()],
        ));

        return true;
    }

    public function delete(string $name): bool
    {
        $ref = $this->rootRef($name);
        $existing = $this->files()[$ref] ?? null;
        if (!$existing instanceof ConfigSyncFile) {
            return false;
        }
        $this->activator->activate(new ConfigurationActivationRequest(
            $this->requestId('delete', $ref),
            $this->observedToken,
            [],
            [$ref => $existing->contentHash()],
        ));

        return true;
    }

    public function rename(string $name, string $newName): bool
    {
        $oldRef = $this->rootRef($name);
        $newRef = $this->rootRef($newName);
        $existing = $this->files()[$oldRef] ?? null;
        if (!$existing instanceof ConfigSyncFile || isset($this->files()[$newRef])) {
            return false;
        }
        [$entityType, $entityId] = explode('.', $newRef, 2);
        $replacement = new ConfigSyncFile(
            $entityType,
            $entityId,
            ConfigSyncFile::deterministicUuid($entityType, $entityId),
            $existing->dependencies,
            $existing->langcode,
            $existing->fields,
        );
        $this->activator->activate(new ConfigurationActivationRequest(
            $this->requestId('rename', $oldRef . ':' . $newRef),
            $this->observedToken,
            [$replacement],
            [$oldRef => $existing->contentHash()],
        ));

        return true;
    }

    public function deleteAll(string $prefix = ''): bool
    {
        $files = $this->files();
        $retained = [];
        $tombstones = [];
        foreach ($files as $ref => $file) {
            if ($prefix === '' || str_starts_with($ref, $prefix)) {
                $tombstones[$ref] = $file->contentHash();
            } else {
                $retained[] = $file;
            }
        }
        if ($tombstones === []) {
            return true;
        }
        $expectedEntryHashes = [];
        foreach ($retained as $file) {
            $expectedEntryHashes[$file->ref()] = $file->contentHash();
        }
        $this->activator->activate(new ConfigurationActivationRequest(
            $this->requestId('delete-all', $prefix),
            $this->observedToken,
            $retained,
            $tombstones,
            expectedEntryHashes: $expectedEntryHashes,
            completeReplacement: true,
        ));

        return true;
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

    /** @return array<string, ConfigSyncFile> */
    private function files(): array
    {
        if ($this->observedFiles === null) {
            $this->observedFiles = [];
            foreach ($this->activator->readGeneration($this->observedToken) as $file) {
                $this->observedFiles[$file->ref()] = $file;
            }
        }

        return $this->observedFiles;
    }

    private function rootRef(string $name): string
    {
        if ($this->collection !== '' || preg_match(ConfigSyncFile::REF_PATTERN . 'D', $name) !== 1) {
            throw new ConfigurationAuthorityUnavailableException(
                'Transactional configuration mutation currently requires one canonical root config ref.',
            );
        }

        return $name;
    }

    private function requestId(string $operation, string $scope): string
    {
        return 'config-' . $operation . ':' . substr(hash('sha256', $scope), 0, 16) . ':' . bin2hex(random_bytes(16));
    }
}
