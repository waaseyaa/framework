<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Drift;

use Waaseyaa\Config\Manifest\ConfigSyncBundleManifest;
use Waaseyaa\Config\Manifest\EffectiveGenerationManifest;
use Waaseyaa\Config\Schema\ConfigContentHasher;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigBundleDiagnostic;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidator;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Config\Sync\ValidatedConfigSyncEntry;

/** Complete read-only comparison of authored, effective, active, and artifact state. @api */
final readonly class ConfigDriftVerifier
{
    /** @param array<string, string> $artifactPaths */
    public function __construct(
        private string $syncPath,
        private ConfigSyncBundleValidator $bundleValidator,
        private ConfigSchemaRegistry $registry,
        private ConfigPackageCompatibility $compatibility,
        private ConfigDriftSnapshotReaderInterface $snapshotReader,
        private array $artifactPaths,
        private ConfigContentHasher $hasher = new ConfigContentHasher(),
        private ConfigSyncSerializer $serializer = new ConfigSyncSerializer(),
    ) {}

    public function verify(): ConfigDriftVerificationResult
    {
        $before = $this->artifactSnapshot();
        $snapshot = $this->snapshotReader->readSnapshot();
        $diagnostics = [];
        $sync = $this->bundleValidator->validate($this->syncPath);
        array_push($diagnostics, ...$sync->diagnostics);
        if ($sync->isValid()) {
            $this->verifyValidBundle($sync, $snapshot, $diagnostics);
        }
        $after = $this->artifactSnapshot();
        foreach ($before as $name => $hash) {
            if (($after[$name] ?? null) !== $hash) {
                $diagnostics[] = new ConfigBundleDiagnostic($name, 'non-mutation', '', 'Artifact changed during read-only drift verification.');
            }
        }
        usort($diagnostics, static fn(ConfigBundleDiagnostic $left, ConfigBundleDiagnostic $right): int => [
            $left->path,
            $left->phase,
            $left->field,
        ] <=> [$right->path, $right->phase, $right->field]);

        return new ConfigDriftVerificationResult($snapshot->activeToken, $diagnostics, $before, $after);
    }

    /** @param list<ConfigBundleDiagnostic> $diagnostics */
    private function verifyValidBundle(
        ConfigSyncBundleValidationResult $sync,
        ConfigDriftSnapshot $snapshot,
        array &$diagnostics,
    ): void {
        try {
            $stored = ConfigSyncBundleManifest::fromCanonicalBytes($snapshot->authoredManifestBytes);
            if (!hash_equals($snapshot->authoredManifestHash, $stored->manifestHash)) {
                $diagnostics[] = new ConfigBundleDiagnostic('<manifest>', 'provenance', 'manifest_hash', 'Stored authored manifest hash does not match its retained canonical bytes.');
            }
            /** @var array<string, int> $required */
            $required = $stored->document['required_package_contracts'];
            /** @var array<string, string> $evidence */
            $evidence = $stored->document['producer_evidence'];
            $this->compatibility->assertWritableCohort($required);
            foreach ($sync->entries as $entry) {
                $this->compatibility->assertWritable($entry->file, $this->registry);
            }
            $recomputed = ConfigSyncBundleManifest::fromValidatedBundle(
                $sync,
                $this->registry,
                (string) $stored->document['bundle_scope'],
                (int) $stored->document['bundle_sequence'],
                $evidence,
                $required,
            );
            if (!hash_equals($stored->canonicalBytes, $recomputed->canonicalBytes)) {
                $diagnostics[] = new ConfigBundleDiagnostic('<manifest>', 'authored', '', 'Current sync bytes/content do not match the activated authored manifest.');
            }
        } catch (\Throwable $exception) {
            $diagnostics[] = new ConfigBundleDiagnostic('<manifest>', 'authored', '', $exception->getMessage());
        }

        $effective = EffectiveGenerationManifest::fromValidatedBundle($sync);
        if ($snapshot->generationSchemaVersion !== EffectiveGenerationManifest::FORMAT_V1) {
            $diagnostics[] = new ConfigBundleDiagnostic('<generation>', 'effective', 'schema_version', 'Active generation does not declare the CFG-03 effective-manifest format.');
        }
        if (!hash_equals($snapshot->activeToken->generationId, $effective->generationId)
            || !hash_equals($snapshot->generationManifestHash, $effective->generationId)
        ) {
            $diagnostics[] = new ConfigBundleDiagnostic('<generation>', 'effective', 'generation_id', 'Active token/generation row does not match recomputed effective content.');
        }
        if ($snapshot->manifestBundleSequence > $snapshot->replayLastSequence) {
            $diagnostics[] = new ConfigBundleDiagnostic('<manifest>', 'replay', 'bundle_sequence', 'Manifest provenance exceeds committed replay state.');
        }
        if (!$snapshot->replayEvidenceConsistent) {
            $diagnostics[] = new ConfigBundleDiagnostic('<manifest>', 'replay', 'evidence', 'Committed replay state does not match retained activation evidence.');
        }

        $activeEntries = [];
        $activeEntryKeys = [];
        foreach ($snapshot->files as $file) {
            try {
                $this->compatibility->assertReadable($file, $this->registry);
                $bytes = $this->serializer->toYaml($file);
                $hashes = $this->hasher->hash($file, $bytes, $this->registry);
                $key = $file->ref() . '@' . $file->langcode;
                $activeEntryKeys[$key] = true;
                $retainedHash = $snapshot->activeEntryHashes[$key] ?? '';
                if (!hash_equals($retainedHash, $hashes->effectiveEntryHash)) {
                    $diagnostics[] = new ConfigBundleDiagnostic($file->filename(), 'active', 'effective_entry_hash', 'Active entry does not match its retained effective-entry identity.');
                }
                $activeEntries[] = new ValidatedConfigSyncEntry(
                    $file,
                    $bytes,
                    $hashes,
                );
            } catch (\Throwable $exception) {
                $diagnostics[] = new ConfigBundleDiagnostic($file->filename(), 'active', '', $exception->getMessage());
            }
        }
        foreach (array_diff_key($snapshot->activeEntryHashes, $activeEntryKeys) as $key => $_hash) {
            $diagnostics[] = new ConfigBundleDiagnostic($key, 'active', 'effective_entry_hash', 'Retained effective-entry identity has no active entry.');
        }
        if (count($activeEntries) === count($snapshot->files)) {
            $activeEffective = EffectiveGenerationManifest::fromValidatedBundle(
                new ConfigSyncBundleValidationResult($activeEntries, []),
            );
            if (!hash_equals($snapshot->activeToken->generationId, $activeEffective->generationId)) {
                $diagnostics[] = new ConfigBundleDiagnostic('<active>', 'effective', 'generation_id', 'Persisted active entries do not reproduce the active generation identity.');
            }
        }
    }

    /** @return array<string, string> */
    private function artifactSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->artifactPaths as $name => $path) {
            $snapshot[$name] = $this->artifactHash($path);
        }
        ksort($snapshot, \SORT_STRING);

        return $snapshot;
    }

    private function artifactHash(string $path): string
    {
        if (is_link($path)) {
            return 'symlink:' . (string) readlink($path);
        }
        if (is_file($path)) {
            return 'sha256:' . hash_file('sha256', $path);
        }
        if (!is_dir($path)) {
            return 'missing';
        }
        $members = [];
        $root = rtrim(str_replace('\\', '/', $path), '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $absolute = str_replace('\\', '/', $item->getPathname());
            $relative = substr($absolute, strlen($root) + 1);
            $members[$relative] = $item->isLink()
                ? 'symlink:' . (string) readlink($item->getPathname())
                : ($item->isFile() ? 'sha256:' . hash_file('sha256', $item->getPathname()) : 'other');
        }
        ksort($members, \SORT_STRING);

        return 'sha256:' . hash('sha256', json_encode($members, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }
}
