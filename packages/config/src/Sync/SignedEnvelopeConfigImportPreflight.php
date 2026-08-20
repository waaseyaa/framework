<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeFile;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeVerifier;
use Waaseyaa\Config\Manifest\ConfigManifestSignatureVerifierInterface;
use Waaseyaa\Config\Manifest\ConfigReplayStateReaderInterface;
use Waaseyaa\Config\Manifest\VerifiedConfigBundle;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;

/**
 * The importing host's CFG-03 gate (#2430).
 *
 * This is the consumer half of a trust boundary that spans two hosts. An
 * authoring host holding CFG-04 custody signs an envelope beside the authored
 * sync directory; this side holds public trust keys only, and turns those bytes
 * into a {@see VerifiedConfigBundle} — or refuses.
 *
 * Verification happens here, at import time, rather than when the container is
 * composed. Two reasons: reading replay state needs the database, and a
 * verification failure must surface as an actionable refusal from
 * `config:import`, not as a kernel that will not boot.
 *
 * Nothing in this path can attest to itself. The signature covers the canonical
 * manifest; {@see VerifiedConfigBundle::bind()} independently recomputes that
 * manifest from the freshly validated directory and requires byte identity, so
 * authored bytes edited after signing are caught here. Package compatibility
 * comes from the installed cohort, never from the bundle under import.
 *
 * @api
 */
final readonly class SignedEnvelopeConfigImportPreflight implements ConfigImportPreflightInterface
{
    public function __construct(
        private string $syncPath,
        private ConfigSyncBundleValidator $bundleValidator,
        private ConfigSchemaRegistry $registry,
        private ConfigPackageCompatibility $compatibility,
        private ConfigManifestEnvelopeVerifier $envelopeVerifier,
        private ConfigManifestSignatureVerifierInterface $signatureVerifier,
        private ConfigReplayStateReaderInterface $replayState,
    ) {}

    public function assertReady(
        array $syncFiles,
        array $activeRefs,
        bool $dryRun,
        bool $deleteOrphans,
        bool $noDependencyCheck,
    ): VerifiedConfigBundle {
        try {
            $envelope = ConfigManifestEnvelopeFile::read($this->syncPath);
        } catch (\Throwable $exception) {
            // Present and untrustworthy. Distinct from absent, and reported as
            // such so an operator looks at the sidecar rather than authoring a
            // second one beside it.
            throw new ConfigImportPreflightException(
                'CFG-03 configuration manifest envelope is unusable: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if ($envelope === null) {
            // The ordinary state of a freshly installed site: genesis activated
            // an empty generation and no signed bundle has been authored yet.
            // Naming the exact path matters — a bare "import refused" is the
            // failure this whole path is correcting.
            throw new ConfigImportPreflightException(sprintf(
                'Configuration import requires a signed CFG-03 manifest envelope at %s. '
                . 'Author it on a host holding signing custody (waaseyaa config:manifest:sign) and ship it beside the sync directory. '
                . 'Unsigned configuration is refused.',
                ConfigManifestEnvelopeFile::pathFor($this->syncPath),
            ));
        }

        try {
            $verification = $this->envelopeVerifier->verifySigned(
                $envelope,
                $this->signatureVerifier,
                $this->replayState,
            );
        } catch (\Throwable $exception) {
            throw new ConfigImportPreflightException(
                'CFG-03 manifest envelope verification failed: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        return new VerifiedConfigImportPreflight(
            $this->syncPath,
            $this->bundleValidator,
            $this->registry,
            $this->compatibility,
            $verification,
        )->assertReady($syncFiles, $activeRefs, $dryRun, $deleteOrphans, $noDependencyCheck);
    }
}
