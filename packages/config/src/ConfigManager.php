<?php

declare(strict_types=1);

namespace Waaseyaa\Config;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\Authority\ReadOnlyActiveConfigurationStorage;
use Waaseyaa\Config\Event\ConfigEvent;
use Waaseyaa\Config\Event\ConfigEvents;
use Waaseyaa\Config\Exception\ConfigImportFailedException;

/**
 * @api
 */
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

    /**
     * @throws ConfigImportFailedException if a write or delete fails partway through
     *         applying the sync store to the active store. The exception names the
     *         key that failed and reports what had already been applied so far, so
     *         the caller can surface a precise, actionable error instead of a
     *         silently partial import.
     */
    public function import(): ConfigImportResult
    {
        if ($this->activeStorage instanceof ReadOnlyActiveConfigurationStorage) {
            throw new ConfigurationAuthorityUnavailableException(
                'Legacy ConfigManager::import is disabled in production; use the verified one-generation config:import activation path.',
            );
        }
        $syncNames = array_values($this->syncStorage->listAll());
        $activeNames = $this->activeStorage->listAll();

        $created = [];
        $updated = [];
        $deleted = [];
        $errors = [];

        $totalSyncNames = count($syncNames);

        // Determine creates and updates.
        foreach ($syncNames as $position => $name) {
            $syncData = $this->syncStorage->read($name);
            if ($syncData === false) {
                // A sync-side read failure means this entry was never going to be
                // applied in the first place: there is nothing partially applied
                // to protect, unlike a write/delete failure mid-apply. Record and
                // continue rather than aborting the whole import.
                $errors[] = sprintf('Failed to read sync config: %s', $name);
                continue;
            }

            $activeData = $this->activeStorage->read($name);

            if ($activeData === false) {
                // New config.
                if ($this->activeStorage->write($name, $syncData)) {
                    $created[] = $name;
                } else {
                    throw ConfigImportFailedException::applyFailed(
                        $name,
                        $this->describePartialApply(
                            'write failed while creating this entry',
                            $position + 1,
                            $totalSyncNames,
                            $created,
                            $updated,
                            $deleted,
                        ),
                    );
                }
            } elseif ($syncData !== $activeData) {
                // Updated config.
                if ($this->activeStorage->write($name, $syncData)) {
                    $updated[] = $name;
                } else {
                    throw ConfigImportFailedException::applyFailed(
                        $name,
                        $this->describePartialApply(
                            'write failed while updating this entry',
                            $position + 1,
                            $totalSyncNames,
                            $created,
                            $updated,
                            $deleted,
                        ),
                    );
                }
            }
        }

        // Determine deletes: configs in active but not in sync.
        $toDelete = array_values(array_diff($activeNames, $syncNames));
        $totalToDelete = count($toDelete);
        foreach ($toDelete as $position => $name) {
            if ($this->activeStorage->delete($name)) {
                $deleted[] = $name;
            } else {
                throw ConfigImportFailedException::applyFailed(
                    $name,
                    $this->describePartialApply(
                        'delete failed while removing this entry',
                        $position + 1,
                        $totalToDelete,
                        $created,
                        $updated,
                        $deleted,
                    ),
                );
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

    /**
     * Builds a human-readable reason describing a fail-fast abort mid-import:
     * which pass position the failure occurred at, and what had already been
     * applied to the active store before the failure.
     *
     * @param string[] $created
     * @param string[] $updated
     * @param string[] $deleted
     */
    private function describePartialApply(
        string $stage,
        int $position,
        int $total,
        array $created,
        array $updated,
        array $deleted,
    ): string {
        return sprintf(
            '%s (entry %d of %d this pass; already applied — created: %s; updated: %s; deleted: %s)',
            $stage,
            $position,
            $total,
            $created === [] ? 'none' : implode(', ', $created),
            $updated === [] ? 'none' : implode(', ', $updated),
            $deleted === [] ? 'none' : implode(', ', $deleted),
        );
    }
}
