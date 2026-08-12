<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Dependency\DependencyResolver;
use Waaseyaa\Config\Dependency\Exception\ConfigDependencyCycleException;
use Waaseyaa\Config\Dependency\Exception\ConfigDependencyMissingException;
use Waaseyaa\Config\Exception\ConfigImportFailedException;

/**
 * Orchestrates `config:import`:
 *
 *  1. Enumerate every sync file via {@see ConfigSyncRepository::list()}.
 *  2. Build the dependency graph via {@see DependencyResolver::resolve()}
 *     (skipped when `noDependencyCheck`).
 *  3. Apply each entry in topological order via the
 *     {@see ConfigImportApplyHookInterface} hook (skipped in `dryRun`).
 *  4. Handle orphans (active-store entities with no sync file): default
 *     warn-only via `config.audit`; `deleteOrphans` opts into deletion.
 *
 * Failure modes (FR-027, FR-028):
 *  - Per-entity failure: caught, recorded as STATUS_FAILED, run continues
 *    unless `haltOnError`.
 *  - `--no-dependency-check`: bypass flag — logged as a warning to
 *    `config.audit` per cli-namespace.md.
 *  - Cycles / missing deps from the resolver: surfaced as STATUS_FAILED
 *    entries (one per affected ref) and abort the apply loop.
 *
 * Stability scope (charter §5.5): the class FQCN, the `import()` signature,
 * and the parameter names are on stable surface for `waaseyaa/config` v1.x.
 * The constructor seams (audit hook, hook) may evolve additively.
 *
 * **Audit logging contract:** the constructor accepts a duck-typed
 * `?callable` (signature `function(string $level, string $message, array $context): void`).
 * App wiring typically passes a closure that fans messages into the
 * `config.audit` channel of {@see \Waaseyaa\Foundation\Log\LoggerInterface};
 * the config package itself stays Layer 1 by avoiding the foundation
 * import. `$level` is one of `'info'`, `'warning'`, mirroring PSR-3.
 *
 * @api
 */
final class ConfigImporter
{
    /** @var (callable(string, string, array<string, mixed>): void)|null */
    private $auditLogger;

    /**
     * @param (callable(string, string, array<string, mixed>): void)|null $auditLogger
     *      Audit-log sink for `config.audit` events. Signature:
     *      `function(string $level, string $message, array $context): void`.
     *      `null` (the default) disables audit logging.
     */
    public function __construct(
        private readonly ConfigSyncRepository $repository,
        private readonly ConfigImportApplyHookInterface $applyHook,
        private readonly ConfigImportPreflightInterface $preflight,
        private readonly DependencyResolver $resolver = new DependencyResolver(),
        ?callable $auditLogger = null,
    ) {
        $this->auditLogger = $auditLogger;
    }

    /**
     * Run the import.
     *
     * @param bool          $dryRun             Compute outcomes without writing.
     * @param bool          $deleteOrphans      Delete active-store orphans (default: warn only).
     * @param bool          $haltOnError        Stop after first per-entity failure.
     * @param bool          $noDependencyCheck  Emergency bypass: skip validation AND DAG.
     * @param list<string>  $activeRefs         Refs present in the active store (for orphan detection
     *                                          and to satisfy cross-store deps in the resolver).
     */
    public function import(
        bool $dryRun = false,
        bool $deleteOrphans = false,
        bool $haltOnError = false,
        bool $noDependencyCheck = false,
        array $activeRefs = [],
    ): ConfigImportResult {
        $syncFiles = $this->collectSyncFiles();
        $entries = [];

        $this->preflight->assertReady(
            syncFiles: $syncFiles,
            activeRefs: $activeRefs,
            dryRun: $dryRun,
            deleteOrphans: $deleteOrphans,
            noDependencyCheck: $noDependencyCheck,
        );

        if ($noDependencyCheck) {
            $this->audit(
                'warning',
                'config:import bypass: --no-dependency-check used; DAG ordering and validation skipped.',
                ['sync_count' => \count($syncFiles)],
            );
            $orderedRefs = array_keys($syncFiles);
        } else {
            try {
                $orderedRefs = $this->orderRefs($syncFiles, $activeRefs);
            } catch (ConfigDependencyCycleException $e) {
                $entries[] = new ConfigImportEntryResult(
                    ref: $e->cyclePath[0] ?? '<unknown>',
                    status: ConfigImportEntryResult::STATUS_FAILED,
                    reason: $e->getMessage(),
                );

                return new ConfigImportResult(entries: $entries, dryRun: $dryRun);
            } catch (ConfigDependencyMissingException $e) {
                $entries[] = new ConfigImportEntryResult(
                    ref: $e->requiredBy,
                    status: ConfigImportEntryResult::STATUS_FAILED,
                    reason: $e->getMessage(),
                );

                return new ConfigImportResult(entries: $entries, dryRun: $dryRun);
            }
        }

        foreach ($orderedRefs as $ref) {
            $entry = $this->processOne($syncFiles[$ref], $dryRun);
            $entries[] = $entry;
            if ($entry->isFailure() && $haltOnError) {
                return new ConfigImportResult(entries: $entries, dryRun: $dryRun);
            }
        }

        // Orphan pass — only after all sync entries have been processed, so
        // we never delete an active-store entity that a later import would
        // recreate. Orphan detection is intentionally trivial: any ref in
        // `$activeRefs` not present in `$syncFiles` is an orphan.
        $syncRefs = array_flip(array_keys($syncFiles));
        foreach ($activeRefs as $activeRef) {
            if (\array_key_exists($activeRef, $syncRefs)) {
                continue;
            }
            $entries[] = $this->handleOrphan($activeRef, $deleteOrphans, $dryRun);
        }

        return new ConfigImportResult(entries: $entries, dryRun: $dryRun);
    }

    /**
     * @return array<string, ConfigSyncFile> Keyed by ref, deterministic iteration.
     */
    private function collectSyncFiles(): array
    {
        $files = [];
        foreach ($this->repository->list() as $file) {
            $files[$file->ref()] = $file;
        }
        ksort($files, \SORT_STRING);

        return $files;
    }

    /**
     * @param array<string, ConfigSyncFile> $syncFiles
     * @param list<string>                  $activeRefs
     *
     * @return list<string> Refs in topological order.
     *
     * @throws ConfigDependencyCycleException
     * @throws ConfigDependencyMissingException
     */
    private function orderRefs(array $syncFiles, array $activeRefs): array
    {
        $declarations = [];
        foreach ($syncFiles as $ref => $file) {
            $declarations[$ref] = $file->dependencies;
        }

        $graph = $this->resolver->resolve($declarations, $activeRefs);

        return $graph->topologicalOrder;
    }

    private function processOne(ConfigSyncFile $file, bool $dryRun): ConfigImportEntryResult
    {
        $ref = $file->ref();

        if ($dryRun) {
            // Dry-run reports a conservative STATUS_UPDATED for every
            // would-apply entry. The CLI prefixes each output line with
            // `[dry-run]` so operators can distinguish. Per-entry
            // created/updated/unchanged diffing via ConfigDiffer is a
            // deliberate follow-up not yet wired into this path.
            return new ConfigImportEntryResult(
                ref: $ref,
                status: ConfigImportEntryResult::STATUS_UPDATED,
                reason: null,
            );
        }

        try {
            $status = $this->applyHook->apply($file);
        } catch (ConfigImportFailedException $e) {
            return new ConfigImportEntryResult(
                ref: $ref,
                status: ConfigImportEntryResult::STATUS_FAILED,
                reason: $e->getMessage(),
            );
        } catch (\Throwable $e) {
            // Wrap untyped throwables so the CLI sees the framework error
            // code rather than leaking implementation-specific exceptions.
            $wrapped = ConfigImportFailedException::applyFailed($ref, $e->getMessage(), $e);

            return new ConfigImportEntryResult(
                ref: $ref,
                status: ConfigImportEntryResult::STATUS_FAILED,
                reason: $wrapped->getMessage(),
            );
        }

        return new ConfigImportEntryResult(ref: $ref, status: $status);
    }

    private function handleOrphan(string $ref, bool $deleteOrphans, bool $dryRun): ConfigImportEntryResult
    {
        if (!$deleteOrphans) {
            $this->audit(
                'info',
                sprintf('config:import orphan retained: %s (no matching sync file).', $ref),
                ['ref' => $ref, 'mode' => 'warn'],
            );

            return new ConfigImportEntryResult(
                ref: $ref,
                status: ConfigImportEntryResult::STATUS_UNCHANGED,
                reason: 'orphan retained (use --delete-orphans to remove)',
            );
        }

        if ($dryRun) {
            return new ConfigImportEntryResult(
                ref: $ref,
                status: ConfigImportEntryResult::STATUS_DELETED,
                reason: 'orphan would be deleted',
            );
        }

        try {
            $this->applyHook->delete($ref);
        } catch (ConfigImportFailedException $e) {
            return new ConfigImportEntryResult(
                ref: $ref,
                status: ConfigImportEntryResult::STATUS_FAILED,
                reason: $e->getMessage(),
            );
        } catch (\Throwable $e) {
            $wrapped = ConfigImportFailedException::applyFailed($ref, $e->getMessage(), $e);

            return new ConfigImportEntryResult(
                ref: $ref,
                status: ConfigImportEntryResult::STATUS_FAILED,
                reason: $wrapped->getMessage(),
            );
        }

        $this->audit(
            'info',
            sprintf('config:import orphan deleted: %s.', $ref),
            ['ref' => $ref, 'mode' => 'delete'],
        );

        return new ConfigImportEntryResult(ref: $ref, status: ConfigImportEntryResult::STATUS_DELETED);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(string $level, string $message, array $context): void
    {
        if ($this->auditLogger === null) {
            return;
        }
        ($this->auditLogger)($level, $message, $context);
    }
}
