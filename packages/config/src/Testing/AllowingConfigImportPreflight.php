<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Testing;

use Waaseyaa\Config\Manifest\VerifiedConfigBundle;
use Waaseyaa\Config\Sync\ConfigImportPreflightInterface;

/** Explicit test-only proof gate; production providers must never bind it. */
final class AllowingConfigImportPreflight implements ConfigImportPreflightInterface
{
    public function assertReady(
        array $syncFiles,
        array $activeRefs,
        bool $dryRun,
        bool $deleteOrphans,
        bool $noDependencyCheck,
    ): ?VerifiedConfigBundle {
        return null;
    }
}
