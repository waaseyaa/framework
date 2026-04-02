<?php

declare(strict_types=1);

namespace Waaseyaa\Config;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Config\Event\ConfigEvent;
use Waaseyaa\Config\Event\ConfigEvents;

final class ConfigManager implements ConfigManagerInterface
{
    public function __construct(
        private readonly StorageInterface $activeStorage,
        private readonly StorageInterface $syncStorage,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function getActiveStorage(): StorageInterface
    {
        return $this->activeStorage;
    }

    public function getSyncStorage(): StorageInterface
    {
        return $this->syncStorage;
    }

    public function import(): ConfigImportResult
    {
        $syncNames = $this->syncStorage->listAll();
        $activeNames = $this->activeStorage->listAll();

        $created = [];
        $updated = [];
        $deleted = [];
        $errors = [];

        // Determine creates and updates.
        foreach ($syncNames as $name) {
            $syncData = $this->syncStorage->read($name);
            if ($syncData === false) {
                $errors[] = sprintf('Failed to read sync config: %s', $name);
                continue;
            }

            $activeData = $this->activeStorage->read($name);

            if ($activeData === false) {
                // New config.
                if ($this->activeStorage->write($name, $syncData)) {
                    $created[] = $name;
                } else {
                    $errors[] = sprintf('Failed to create config: %s', $name);
                }
            } elseif ($syncData !== $activeData) {
                // Updated config.
                if ($this->activeStorage->write($name, $syncData)) {
                    $updated[] = $name;
                } else {
                    $errors[] = sprintf('Failed to update config: %s', $name);
                }
            }
        }

        // Determine deletes: configs in active but not in sync.
        $toDelete = array_diff($activeNames, $syncNames);
        foreach ($toDelete as $name) {
            if ($this->activeStorage->delete($name)) {
                $deleted[] = $name;
            } else {
                $errors[] = sprintf('Failed to delete config: %s', $name);
            }
        }

        $result = new ConfigImportResult(
            created: $created,
            updated: $updated,
            deleted: $deleted,
            errors: $errors,
        );

        $this->eventDispatcher->dispatch(
            new ConfigEvent('', ['result' => $result]),
            ConfigEvents::IMPORT->value,
        );

        return $result;
    }

    public function export(): void
    {
        // Clear the sync storage.
        $this->syncStorage->deleteAll();

        // Copy all active configs to sync.
        $activeNames = $this->activeStorage->listAll();

        foreach ($activeNames as $name) {
            $data = $this->activeStorage->read($name);
            if ($data !== false) {
                $this->syncStorage->write($name, $data);
            }
        }
    }

    public function diff(string $configName): array
    {
        $activeData = $this->activeStorage->read($configName);
        $syncData = $this->syncStorage->read($configName);

        return [
            'active' => $activeData !== false ? $activeData : null,
            'sync' => $syncData !== false ? $syncData : null,
            'has_changes' => $activeData !== $syncData,
        ];
    }
}
