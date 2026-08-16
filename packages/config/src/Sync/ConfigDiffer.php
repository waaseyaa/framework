<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

/**
 * Renders unified diffs between the sync store and the active store and
 * classifies each ref as in-sync / drift / sync-only / active-only / renamed.
 *
 * Both sides are projected through {@see ConfigSyncSerializer::toDiagnosticYaml()}
 * before diffing, so byte-identical {@see ConfigSyncFile} values produce
 * byte-identical YAML and therefore zero diff (FR-031: "both sides
 * serialize identically before diffing to avoid spurious whitespace
 * differences").
 *
 * UUID-tracked rename detection (FR-033): when an active-store entity carries
 * a `_meta.uuid` that also appears in the sync store under a *different*
 * ref, the diff classifies the active-side ref as {@see DiffResult::STATUS_RENAMED}
 * with `renamedFrom` set to the active ref. The active-only entry is
 * suppressed (it is now accounted for under the renamed entry).
 *
 * Stability scope: INTERNAL. The CLI commands ({@see \Waaseyaa\CLI\Command\Config\ConfigDiffCommand},
 * {@see \Waaseyaa\CLI\Command\Config\ConfigStatusCommand}) consume this and
 * map it onto stable exit codes + output. Downstream code that wants
 * machine-readable status should use `bin/waaseyaa config:status --format=json`.
 *
 * @api
 */
final class ConfigDiffer
{
    public function __construct(
        private readonly ConfigSyncRepository $syncRepository,
        private readonly ConfigSyncFileSourceInterface $activeSource,
        private readonly ConfigSyncSerializer $serializer = new ConfigSyncSerializer(),
    ) {}

    /**
     * Compute diffs for every ref present in either store.
     *
     * Results are deterministically sorted alphabetically by ref so output
     * is stable across runs.
     *
     * @return list<DiffResult>
     */
    public function diffAll(): array
    {
        [$syncFiles, $activeFiles] = $this->collectBothSides();

        return $this->buildResults($syncFiles, $activeFiles);
    }

    /**
     * Compute a diff scoped to one ref. Returns null when the ref is absent
     * from both stores (operator-facing "unknown ref" — callers should map
     * to a non-zero exit code, but classification is left to the caller).
     */
    public function diff(string $ref): ?DiffResult
    {
        [$syncFiles, $activeFiles] = $this->collectBothSides();

        $results = $this->buildResults($syncFiles, $activeFiles);
        foreach ($results as $result) {
            if ($result->ref === $ref || $result->renamedFrom === $ref) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return array{0: array<string, ConfigSyncFile>, 1: array<string, ConfigSyncFile>}
     *         [syncByRef, activeByRef] — both keyed by ref, sorted alphabetically.
     */
    private function collectBothSides(): array
    {
        $syncFiles = [];
        foreach ($this->syncRepository->list() as $file) {
            $syncFiles[$file->ref()] = $file;
        }
        ksort($syncFiles, \SORT_STRING);

        $activeFiles = [];
        foreach ($this->activeSource->iterate() as $file) {
            $activeFiles[$file->ref()] = $file;
        }
        ksort($activeFiles, \SORT_STRING);

        return [$syncFiles, $activeFiles];
    }

    /**
     * @param array<string, ConfigSyncFile> $syncFiles
     * @param array<string, ConfigSyncFile> $activeFiles
     *
     * @return list<DiffResult>
     */
    private function buildResults(array $syncFiles, array $activeFiles): array
    {
        // Pre-compute the rename map: any active ref whose uuid matches a
        // *different* sync ref is a rename target. We classify the rename
        // under the sync ref (the "new" name) and suppress the active-only
        // entry that would otherwise appear under the old name.
        $syncUuidIndex = $this->indexByUuid($syncFiles);
        $renamedFromByNewRef = [];
        $suppressedActiveRefs = [];
        foreach ($activeFiles as $activeRef => $activeFile) {
            if (isset($syncFiles[$activeRef])) {
                continue; // identity match wins over rename detection
            }
            $newRef = $syncUuidIndex[$activeFile->uuid] ?? null;
            if ($newRef === null || $newRef === $activeRef) {
                continue;
            }
            // Only consider it a rename when the new ref is not also present
            // on the active side (otherwise the active store already has the
            // renamed entity — no diff needed for the rename itself).
            if (isset($activeFiles[$newRef])) {
                continue;
            }
            $renamedFromByNewRef[$newRef] = $activeRef;
            $suppressedActiveRefs[$activeRef] = true;
        }

        $allRefs = array_unique(array_merge(array_keys($syncFiles), array_keys($activeFiles)));
        sort($allRefs, \SORT_STRING);

        $results = [];
        foreach ($allRefs as $ref) {
            $syncFile = $syncFiles[$ref] ?? null;
            $activeFile = $activeFiles[$ref] ?? null;

            if (isset($suppressedActiveRefs[$ref])) {
                // This ref's active-only diff is rolled into the rename
                // entry under the new ref; skip emitting it separately.
                continue;
            }

            if ($syncFile !== null && isset($renamedFromByNewRef[$ref]) && $activeFile === null) {
                $oldRef = $renamedFromByNewRef[$ref];
                $oldActive = $activeFiles[$oldRef] ?? null;
                $results[] = $this->buildRenamedResult($ref, $syncFile, $oldRef, $oldActive);
                continue;
            }

            if ($syncFile !== null && $activeFile !== null) {
                $results[] = $this->buildIdentityResult($ref, $syncFile, $activeFile);
                continue;
            }

            if ($syncFile !== null) {
                $results[] = $this->buildSyncOnlyResult($ref, $syncFile);
                continue;
            }

            // $activeFile !== null && $syncFile === null
            $results[] = $this->buildActiveOnlyResult($ref, $activeFile);
        }

        return $results;
    }

    /**
     * @param array<string, ConfigSyncFile> $files
     *
     * @return array<string, string> uuid => ref
     */
    private function indexByUuid(array $files): array
    {
        $index = [];
        foreach ($files as $ref => $file) {
            // First occurrence wins — duplicate UUIDs across refs are an
            // upstream data error; the dependency-resolver / status report
            // already surface them.
            $index[$file->uuid] ??= $ref;
        }

        return $index;
    }

    private function buildIdentityResult(string $ref, ConfigSyncFile $syncFile, ConfigSyncFile $activeFile): DiffResult
    {
        $syncYaml = $this->serializer->toDiagnosticYaml($syncFile);
        $activeYaml = $this->serializer->toDiagnosticYaml($activeFile);

        if ($syncYaml === $activeYaml) {
            return new DiffResult(
                ref: $ref,
                status: DiffResult::STATUS_IN_SYNC,
                uuid: $syncFile->uuid,
            );
        }

        return new DiffResult(
            ref: $ref,
            status: DiffResult::STATUS_DRIFT,
            diff: $this->unifiedDiff($activeYaml, $syncYaml, "a/{$ref}", "b/{$ref}"),
            uuid: $syncFile->uuid,
        );
    }

    private function buildSyncOnlyResult(string $ref, ConfigSyncFile $syncFile): DiffResult
    {
        $syncYaml = $this->serializer->toDiagnosticYaml($syncFile);

        return new DiffResult(
            ref: $ref,
            status: DiffResult::STATUS_SYNC_ONLY,
            diff: $this->unifiedDiff('', $syncYaml, '/dev/null', "b/{$ref}"),
            uuid: $syncFile->uuid,
        );
    }

    private function buildActiveOnlyResult(string $ref, ConfigSyncFile $activeFile): DiffResult
    {
        $activeYaml = $this->serializer->toDiagnosticYaml($activeFile);

        return new DiffResult(
            ref: $ref,
            status: DiffResult::STATUS_ACTIVE_ONLY,
            diff: $this->unifiedDiff($activeYaml, '', "a/{$ref}", '/dev/null'),
            uuid: $activeFile->uuid,
        );
    }

    private function buildRenamedResult(
        string $newRef,
        ConfigSyncFile $syncFile,
        string $oldRef,
        ?ConfigSyncFile $oldActive,
    ): DiffResult {
        $syncYaml = $this->serializer->toDiagnosticYaml($syncFile);
        $activeYaml = $oldActive !== null ? $this->serializer->toDiagnosticYaml($oldActive) : '';

        return new DiffResult(
            ref: $newRef,
            status: DiffResult::STATUS_RENAMED,
            diff: $this->unifiedDiff($activeYaml, $syncYaml, "a/{$oldRef}", "b/{$newRef}"),
            renamedFrom: $oldRef,
            uuid: $syncFile->uuid,
        );
    }

    /**
     * Minimal unified-diff renderer.
     *
     * We deliberately keep the renderer dependency-free: no `sebastian/diff`
     * import (Foundation-layer hygiene) and no external Composer package
     * beyond Symfony YAML (already required transitively). The output is the
     * concatenation of:
     *
     *   --- $oldLabel
     *   +++ $newLabel
     *   @@ -1,N +1,M @@
     *   -<every old line>
     *   +<every new line>
     *
     * This produces a valid unified-diff stream readable by `patch(1)` and
     * by humans. We do not run a Myers diff because the canonical YAML for
     * one config entity is small (rarely > 200 lines) and operators read
     * full before/after blocks more easily than a minimal edit script.
     */
    private function unifiedDiff(string $oldText, string $newText, string $oldLabel, string $newLabel): string
    {
        if ($oldText === $newText) {
            return '';
        }

        $oldLines = $oldText === '' ? [] : preg_split("/\r\n|\n|\r/", rtrim($oldText, "\r\n"));
        $newLines = $newText === '' ? [] : preg_split("/\r\n|\n|\r/", rtrim($newText, "\r\n"));
        if ($oldLines === false) {
            $oldLines = [];
        }
        if ($newLines === false) {
            $newLines = [];
        }

        $output = '';
        $output .= sprintf("--- %s\n", $oldLabel);
        $output .= sprintf("+++ %s\n", $newLabel);

        $oldCount = \count($oldLines);
        $newCount = \count($newLines);
        $oldStart = $oldCount === 0 ? 0 : 1;
        $newStart = $newCount === 0 ? 0 : 1;
        $output .= sprintf("@@ -%d,%d +%d,%d @@\n", $oldStart, $oldCount, $newStart, $newCount);

        foreach ($oldLines as $line) {
            $output .= '-' . $line . "\n";
        }
        foreach ($newLines as $line) {
            $output .= '+' . $line . "\n";
        }

        return $output;
    }
}
