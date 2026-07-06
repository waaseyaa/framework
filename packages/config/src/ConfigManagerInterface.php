<?php

declare(strict_types=1);

namespace Waaseyaa\Config;

use Waaseyaa\Config\Exception\ConfigImportFailedException;

/**
 * @api
 */
interface ConfigManagerInterface
{
    public function getActiveStorage(): StorageInterface;

    public function getSyncStorage(): StorageInterface;

    /**
     * @throws ConfigImportFailedException if a write or delete fails partway
     *         through applying the sync store to the active store.
     */
    public function import(): ConfigImportResult;

    public function export(): void;

    /** @return array<string, mixed> */
    public function diff(string $configName): array;
}
