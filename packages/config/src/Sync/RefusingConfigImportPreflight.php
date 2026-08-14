<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

/** CFG-01 fail-closed implementation used until CFG-02/CFG-03 bind production gates. */
final class RefusingConfigImportPreflight implements ConfigImportPreflightInterface
{
    public function assertReady(
        array $syncFiles,
        array $activeRefs,
        bool $dryRun,
        bool $deleteOrphans,
        bool $noDependencyCheck,
    ): void {
        throw new ConfigImportPreflightException(
            'Configuration import is unavailable until CFG-02 activation and CFG-03 schema, manifest, and drift gates are bound.',
        );
    }
}
