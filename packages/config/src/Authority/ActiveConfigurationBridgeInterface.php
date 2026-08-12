<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

use Waaseyaa\Config\StorageInterface;
use Waaseyaa\Config\Sync\ConfigImportApplyHookInterface;
use Waaseyaa\Config\Sync\ConfigSyncFileSourceInterface;

/** One higher-layer bridge over the active database generation. @api */
interface ActiveConfigurationBridgeInterface extends ConfigSyncFileSourceInterface, ConfigImportApplyHookInterface
{
    public function authorityContext(): ConfigurationAuthorityContext;

    public function activeStorage(): StorageInterface;
}
