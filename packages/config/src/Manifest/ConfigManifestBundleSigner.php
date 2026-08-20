<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigBundleDiagnostic;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidator;

/**
 * The authoring host's CFG-03 producer (#2430).
 *
 * Runs where signing custody lives — a maintainer machine or a protected CI
 * environment — and never on the importing consumer. It validates the authored
 * sync directory, builds the canonical bundle manifest, signs it, and writes the
 * envelope beside the directory. It reads no active configuration and activates
 * nothing: producing an envelope is an authoring act, not a deployment.
 *
 * The framework owns this producer rather than leaving it to each consumer,
 * because canonicalization and manifest binding are the most security-sensitive
 * part of the contract and every independent reimplementation is a chance to get
 * them subtly wrong in a way that still verifies.
 *
 * Determinism: given the same authored bytes, cohort, scope, sequence, and
 * producer evidence, the signed manifest bytes are identical. Only the signature
 * itself depends on the key.
 *
 * @api
 */
final readonly class ConfigManifestBundleSigner
{
    public function __construct(
        private ConfigSyncBundleValidator $bundleValidator,
        private ConfigSchemaRegistry $registry,
        private ConfigPackageCompatibility $compatibility,
        private ConfigManifestSignerInterface $signer,
    ) {}

    /**
     * Sign the bundle at `$syncPath` and write its envelope sidecar.
     *
     * @param array<string, string> $producerEvidence
     */
    public function sign(
        string $syncPath,
        string $bundleScope,
        int $bundleSequence,
        array $producerEvidence,
    ): ConfigManifestSigningResult {
        $result = $this->bundleValidator->validate($syncPath);
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

            throw new \RuntimeException(
                "Refusing to sign a sync directory that does not pass strict CFG-03 validation:\n"
                . implode("\n", $diagnostics),
            );
        }

        // Required contracts are derived from the authored files, then checked
        // against the installed cohort. Deriving them is not self-authorization:
        // assertWritable/assertWritableCohort below refuse anything the
        // authoring host does not actually have installed as writable, so a file
        // cannot name a contract into existence.
        $requiredPackageContracts = [];
        foreach ($result->requireValidEntries() as $entry) {
            $requiredPackageContracts[$entry->file->ownerPackage] = $entry->file->ownerConfigContractVersion;
            $this->compatibility->assertWritable($entry->file, $this->registry);
        }
        ksort($requiredPackageContracts, \SORT_STRING);
        $this->compatibility->assertWritableCohort($requiredPackageContracts);

        $manifest = ConfigSyncBundleManifest::fromValidatedBundle(
            $result,
            $this->registry,
            $bundleScope,
            $bundleSequence,
            $producerEvidence,
            $requiredPackageContracts,
        );

        $envelope = SignedConfigManifestEnvelope::sign($manifest, $this->signer);
        $path = ConfigManifestEnvelopeFile::write($syncPath, $envelope);

        return new ConfigManifestSigningResult(
            envelopePath: $path,
            manifestHash: $manifest->manifestHash,
            bundleScope: $bundleScope,
            bundleSequence: $bundleSequence,
            trustKeyReference: $this->signer->trustKeyReference(),
            entryCount: count($result->requireValidEntries()),
            requiredPackageContracts: $requiredPackageContracts,
        );
    }

    /**
     * The scope an existing sidecar already uses, if any.
     *
     * Scope identifies the authoring authority for replay purposes, so it must
     * stay stable across signatures of the same bundle lineage. Defaulting to
     * the previous value keeps an operator from silently starting a second
     * lineage by omitting a flag.
     */
    public static function previousScope(string $syncPath): ?string
    {
        $existing = ConfigManifestEnvelopeFile::read($syncPath);

        return $existing === null ? null : (string) $existing->protectedHeader['bundle_scope'];
    }

    /**
     * The sequence a fresh signature should carry.
     *
     * Read from the existing sidecar so the authoring host stays independent of
     * the consumer's database: replay state is the importing side's check, and
     * requiring the authoring host to reach it would put the two hosts back
     * together. An operator can always pass an explicit sequence.
     */
    public static function nextSequence(string $syncPath): int
    {
        $existing = ConfigManifestEnvelopeFile::read($syncPath);

        return $existing === null ? 1 : (int) $existing->protectedHeader['bundle_sequence'] + 1;
    }
}
