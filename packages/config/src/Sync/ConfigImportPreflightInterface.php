<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Manifest\VerifiedConfigBundle;

/** Mandatory gate invoked before import can call any active-store mutation seam. @api */
interface ConfigImportPreflightInterface
{
    /**
     * @param array<string, ConfigSyncFile> $syncFiles
     * @param list<string> $activeRefs
     */
    public function assertReady(
        array $syncFiles,
        array $activeRefs,
        bool $dryRun,
        bool $deleteOrphans,
        bool $noDependencyCheck,
    ): ?VerifiedConfigBundle;
}
