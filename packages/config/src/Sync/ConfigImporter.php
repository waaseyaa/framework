<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
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
        private readonly ?ConfigurationActivatorInterface $activator = null,
    ) {
        $this->auditLogger = $auditLogger;
    }

    /**
     * Run the import.
     *
     * @param bool          $dryRun             Compute outcomes without writing.
     * @param bool          $deleteOrphans      Delete active-store orphans (default: warn only).
     * @param bool          $haltOnError        Stop after first per-entity failure.
     * @param bool          $noDependencyCheck  Emergency bypass: skip dependency DAG checks only.
     * @param list<string>  $activeRefs         Refs present in the active store (for orphan detection
     *                                          and to satisfy cross-store deps in the resolver).
     */
    public function import(
        bool $dryRun = false,
        bool $deleteOrphans = false,
        bool $haltOnError = false,
        bool $noDependencyCheck = false,
        array $activeRefs = [],
        ?string $activationRequestId = null,
        ?ConfigurationActiveToken $expectedToken = null,
        array $activeFiles = [],
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
                'config:import bypass: --no-dependency-check used; dependency DAG checks skipped.',
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

        if ($this->activator !== null) {
            return $this->activateGeneration(
                $syncFiles,
                $orderedRefs,
                $deleteOrphans,
                $activationRequestId,
                $expectedToken,
                $activeFiles,
                $dryRun,
            );
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
     * @param array<string, ConfigSyncFile> $syncFiles
     * @param list<string> $orderedRefs
     * @param list<ConfigSyncFile> $activeFiles
     */
    private function activateGeneration(
        array $syncFiles,
        array $orderedRefs,
        bool $deleteOrphans,
        ?string $activationRequestId,
        ?ConfigurationActiveToken $expectedToken,
        array $activeFiles,
        bool $dryRun = false,
    ): ConfigImportResult {
        if (!$dryRun && ($activationRequestId === null || $activationRequestId === '')) {
            return new ConfigImportResult(entries: [new ConfigImportEntryResult(
                ref: '<generation>',
                status: ConfigImportEntryResult::STATUS_FAILED,
                reason: 'production config:import requires --activation-request-id',
            )]);
        }
        $activationRequestId ??= 'dry-run:' . substr(hash('sha256', json_encode([
            'expected' => $expectedToken === null ? null : [
                'generation_id' => $expectedToken->generationId,
                'activation_sequence' => $expectedToken->activationSequence,
            ],
            'refs' => $orderedRefs,
            'delete_orphans' => $deleteOrphans,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 0, 64);

        $activeByRef = [];
        foreach ($activeFiles as $file) {
            $activeByRef[$file->ref()] = $file;
        }
        $orderedFiles = array_map(static fn(string $ref): ConfigSyncFile => $syncFiles[$ref], $orderedRefs);
        $tombstones = [];
        $expectedEntryHashes = [];
        foreach ($orderedFiles as $file) {
            if (isset($activeByRef[$file->ref()])) {
                $expectedEntryHashes[$file->ref()] = $activeByRef[$file->ref()]->contentHash();
            }
        }
        if ($deleteOrphans) {
            foreach ($activeByRef as $ref => $file) {
                if (!isset($syncFiles[$ref])) {
                    $tombstones[$ref] = $file->contentHash();
                }
            }
        }

        $request = new ConfigurationActivationRequest(
            $activationRequestId,
            $expectedToken,
            $orderedFiles,
            $tombstones,
            $expectedEntryHashes,
            completeReplacement: $deleteOrphans,
        );
        $nextByRef = $deleteOrphans ? [] : $activeByRef;
        foreach ($orderedFiles as $file) {
            $nextByRef[$file->ref()] = $file;
        }
        foreach ($tombstones as $ref => $_hash) {
            unset($nextByRef[$ref]);
        }
        ksort($nextByRef, SORT_STRING);
        $manifest = [];
        foreach ($nextByRef as $ref => $file) {
            $manifest[$ref] = $file->contentHash();
        }
        $generationId = hash('sha256', json_encode([
            'schema' => 'configuration-generation.v1',
            'entries' => $manifest,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $planHash = hash('sha256', json_encode([
            'schema' => 'configuration-activation-plan.v1',
            'input_hash' => $request->inputHash(),
            'generation_id' => $generationId,
            'refs' => array_keys($nextByRef),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if ($dryRun) {
            $entries = [];
            foreach ($orderedFiles as $file) {
                $previous = $activeByRef[$file->ref()] ?? null;
                $status = !$previous instanceof ConfigSyncFile
                    ? ConfigImportEntryResult::STATUS_CREATED
                    : (hash_equals($previous->contentHash(), $file->contentHash())
                        ? ConfigImportEntryResult::STATUS_UNCHANGED
                        : ConfigImportEntryResult::STATUS_UPDATED);
                $entries[] = new ConfigImportEntryResult($file->ref(), $status);
            }
            foreach (array_keys($tombstones) as $ref) {
                $entries[] = new ConfigImportEntryResult($ref, ConfigImportEntryResult::STATUS_DELETED);
            }

            return new ConfigImportResult($entries, true, $generationId, $planHash);
        }

        try {
            $activation = $this->activator->activate($request);
        } catch (\Throwable $exception) {
            return new ConfigImportResult(entries: [new ConfigImportEntryResult(
                ref: '<generation>',
                status: ConfigImportEntryResult::STATUS_FAILED,
                reason: ConfigImportFailedException::applyFailed('<generation>', $exception->getMessage(), $exception)->getMessage(),
            )]);
        }

        $entries = [];
        foreach ($orderedFiles as $file) {
            $previous = $activeByRef[$file->ref()] ?? null;
            $status = !$previous instanceof ConfigSyncFile
                ? ConfigImportEntryResult::STATUS_CREATED
                : (hash_equals($previous->contentHash(), $file->contentHash())
                    ? ConfigImportEntryResult::STATUS_UNCHANGED
                    : ConfigImportEntryResult::STATUS_UPDATED);
            $entries[] = new ConfigImportEntryResult($file->ref(), $status);
        }
        foreach (array_keys($tombstones) as $ref) {
            $entries[] = new ConfigImportEntryResult($ref, ConfigImportEntryResult::STATUS_DELETED);
        }
        $this->audit('info', 'config:import generation committed.', [
            'activation_request_id' => $activation->requestId,
            'generation_id' => $activation->token->generationId,
            'activation_sequence' => $activation->token->activationSequence,
            'plan_hash' => $activation->planHash,
            'status' => $activation->status,
        ]);

        return new ConfigImportResult(
            entries: $entries,
            generationId: $activation->token->generationId,
            planHash: $activation->planHash,
        );
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
