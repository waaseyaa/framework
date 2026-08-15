<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Config;

use Waaseyaa\Config\Authority\ActiveConfigurationBridgeInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Config\StorageInterface;
use Waaseyaa\Config\Sync\ConfigImportEntryResult;
use Waaseyaa\Config\Sync\ConfigSyncFile;

/** In-memory active generation selected only by an explicit testing profile. */
final class TestingActiveConfigurationBridge implements ActiveConfigurationBridgeInterface
{
    private MemoryStorage $storage;

    public function __construct(private readonly ConfigurationAuthorityContext $context)
    {
        if ($context->activeGenerationId !== TestingConfigurationGenerationResolver::generationId($context)) {
            throw new \InvalidArgumentException('Testing configuration bridge received a non-testing generation.');
        }
        $this->storage = new MemoryStorage();
    }

    public function authorityContext(): ConfigurationAuthorityContext
    {
        return $this->context;
    }

    public function activeStorage(): StorageInterface
    {
        return $this->storage;
    }

    public function iterate(): iterable
    {
        foreach ($this->storage->listAll() as $name) {
            $data = $this->storage->read($name);
            if ($data === false || !str_contains($name, '.')) {
                continue;
            }
            [$entityType, $entityId] = explode('.', $name, 2);
            ksort($data, SORT_STRING);
            yield ConfigSyncFile::legacyReadable(
                entityType: $entityType,
                entityId: $entityId,
                uuid: ConfigSyncFile::deterministicUuid($entityType, $entityId),
                dependencies: [],
                langcode: 'en',
                fields: $data,
            );
        }
    }

    public function apply(ConfigSyncFile $file): string
    {
        $previous = $this->storage->read($file->ref());
        $this->storage->write($file->ref(), $file->fields);

        return $previous === false
            ? ConfigImportEntryResult::STATUS_CREATED
            : ($previous === $file->fields ? ConfigImportEntryResult::STATUS_UNCHANGED : ConfigImportEntryResult::STATUS_UPDATED);
    }

    public function delete(string $ref): void
    {
        $this->storage->delete($ref);
    }
}
