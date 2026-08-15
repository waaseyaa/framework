<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Manifest\VerifiedConfigBundle;
use Waaseyaa\Config\Manifest\VerifiedConfigManifest;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;

/** Revalidates current authored bytes and binds them to prior envelope verification. @api */
final class VerifiedConfigImportPreflight implements ConfigImportPreflightInterface
{
    public function __construct(
        private readonly string $syncPath,
        private readonly ConfigSyncBundleValidator $bundleValidator,
        private readonly ConfigSchemaRegistry $registry,
        private readonly ConfigPackageCompatibility $compatibility,
        private readonly VerifiedConfigManifest $verification,
    ) {}

    public function assertReady(
        array $syncFiles,
        array $activeRefs,
        bool $dryRun,
        bool $deleteOrphans,
        bool $noDependencyCheck,
    ): VerifiedConfigBundle {
        $result = $this->bundleValidator->validate($this->syncPath);
        if (!$result->isValid()) {
            $diagnostics = array_map(
                static fn(ConfigBundleDiagnostic $diagnostic): string => sprintf(
                    '%s:%s:%s: %s',
                    $diagnostic->path,
                    $diagnostic->phase,
                    $diagnostic->field,
                    $diagnostic->message,
                ),
                $result->diagnostics,
            );
            throw new ConfigImportPreflightException(
                "Strict CFG-03 bundle validation failed:\n" . implode("\n", $diagnostics),
            );
        }

        try {
            return VerifiedConfigBundle::bind($result, $this->registry, $this->verification, $this->compatibility);
        } catch (\Throwable $exception) {
            throw new ConfigImportPreflightException(
                'CFG-03 manifest does not authorize the current complete sync directory: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
