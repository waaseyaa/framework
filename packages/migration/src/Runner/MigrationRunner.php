<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Runner;

use Symfony\Component\Uid\Uuid;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Migration\ContentModel\ContentModelRegistrar;
use Waaseyaa\Migration\ContentModel\DerivesContentModelInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\Exception\DestinationWriteException;
use Waaseyaa\Migration\Exception\MigrationAbortedException;
use Waaseyaa\Migration\Exception\ProcessException;
use Waaseyaa\Migration\Exception\SourceReadException;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\MigrationRunState;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

/**
 * Procedural orchestrator for a single migration end-to-end run.
 *
 * Composition seam between WP02 ({@see MigrationRegistry}), WP04 ({@see MigrationIdMap})
 * and the WP05 destination plugin. The runner does not own discovery — it
 * accepts the booted registry — and does not own the database — it accepts
 * the id-map repository.
 *
 * One run id (UUIDv7) is minted per {@see run()} invocation and threaded
 * through the destination via {@see EntityDestination::withRunId()} so every
 * id-map row produced by the run shares a single audit handle (WP06 forward
 * to WP07 resume / WP08 rollback).
 *
 * Error handling follows the spec §10 dichotomy:
 *  - Per-record errors ({@see SourceReadException}, {@see ProcessException},
 *    {@see DestinationWriteException}) are captured in the report (FR-046)
 *    and the loop continues unless `$options->haltOnError` flips on (FR-047).
 *  - Run-level errors (any other `\Throwable` plus source-mid-iteration
 *    generator throws) always halt regardless of flags (FR-048).
 *
 * @api
 *
 * @spec FR-032 — `import:run` single-migration orchestration
 * @spec FR-039 — dry-run flag honored at write boundary
 * @spec FR-040 — limit option short-circuits iteration
 * @spec FR-046 — per-record error capture (capped at {@see RunReport::ERROR_CAP})
 * @spec FR-047 — halt-on-error propagation
 * @spec FR-048 — run-level abort surface
 * @spec FR-037 — resume from the prior run's checkpoint
 * @spec FR-038 — per-record progress recorded into `migration_run_state`
 */
final class MigrationRunner
{
    private readonly LoggerInterface $logger;
    private readonly \Closure $clock;

    /** @var iterable<DerivesContentModelInterface> */
    private readonly iterable $contentModelProviders;

    /**
     * G-026 (#1940): guards content-model registration to once per runner
     * instance (the runner is a container singleton, i.e. once per CLI
     * process) so it runs exactly before the FIRST migration of the
     * invocation and never again, regardless of how many times `run()` /
     * `runResume()` are called in the same `import:run-all` pass. See
     * {@see ensureContentModelsRegistered()}.
     */
    private bool $contentModelsRegistered = false;

    /**
     * @param MigrationRegistry $registry Booted registry; resolved per `run()` via {@see MigrationRegistry::get()}.
     * @param ProcessChainExecutor $chain Runtime collaborator that pipes one source field through its process chain.
     * @param MigrationIdMap $idMap Stable-surface id-map repository, used to inject the `$lookup` closure for process plugins (FR-028).
     * @param ?LoggerInterface $logger Optional structured logger. Defaults to {@see NullLogger}.
     * @param ?MigrationRunState $runState Optional per-record progress writer (WP07). When `null` the runner skips heartbeat writes — preserves WP06's test scaffolding.
     * @param \Closure|null $clock Test seam returning `\DateTimeImmutable`. Defaults to `now in UTC`.
     * @param ?ContentModelRegistrar $contentModelRegistrar Registrar invoked once, before the first migration of this
     *        runner's lifetime, against every provider in `$contentModelProviders` (G-026, #1940). `null` (the WP06
     *        test-scaffolding default) skips content-model registration entirely — existing callers that construct
     *        the runner without it are unaffected.
     * @param iterable<DerivesContentModelInterface> $contentModelProviders Providers collected by the kernel
     *        (`AbstractKernel::injectContentModelProviders()`) or injected directly by tests.
     */
    public function __construct(
        private readonly MigrationRegistry $registry,
        private readonly ProcessChainExecutor $chain,
        private readonly MigrationIdMap $idMap,
        ?LoggerInterface $logger = null,
        private readonly ?MigrationRunState $runState = null,
        ?\Closure $clock = null,
        private readonly ?ContentModelRegistrar $contentModelRegistrar = null,
        iterable $contentModelProviders = [],
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock ?? static fn(): \DateTimeImmutable
            => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->contentModelProviders = $contentModelProviders;
    }

    /**
     * Register every provider's derived content model exactly once, before
     * the first migration this runner instance executes (G-026, #1940).
     *
     * This is the import-time invocation point the pass-1 build was missing:
     * the kernel only ever COLLECTS `DerivesContentModelInterface` providers
     * at boot (`AbstractKernel::injectContentModelProviders()`); calling
     * `deriveContentModel()` and feeding the result through
     * {@see ContentModelRegistrar} happens here instead, at the first
     * `import:*` command that actually runs a migration — by which point
     * `db:init`'s schema sync has already completed and the destination
     * tables the derived model describes are guaranteed to exist. A provider
     * constructed and invoked eagerly during kernel boot's schema-sync phase
     * (the pass-1 shape) has no such guarantee and silently no-ops.
     *
     * A no-op when no {@see ContentModelRegistrar} was injected (WP06 test
     * scaffolding and any caller that does not need content-model
     * registration) — existing `MigrationRunner` construction sites are
     * unaffected.
     */
    private function ensureContentModelsRegistered(): void
    {
        if ($this->contentModelsRegistered) {
            return;
        }

        if ($this->contentModelRegistrar === null) {
            $this->contentModelsRegistered = true;

            return;
        }

        foreach ($this->contentModelProviders as $provider) {
            $model = $provider->deriveContentModel();
            if ($model === null) {
                continue;
            }
            $this->contentModelRegistrar->register($model);
        }

        // Latch only after every provider succeeded: if register() (or
        // deriveContentModel()) threw, a retry on the same long-lived runner
        // instance must re-attempt registration rather than silently run
        // migrations without the content model in place.
        $this->contentModelsRegistered = true;
    }

    /**
     * Execute one migration end-to-end. Returns a {@see RunReport} on
     * normal completion; raises {@see MigrationAbortedException} on halt.
     *
     * @throws MigrationAbortedException Raised on `--halt-on-error` per-record halt (FR-047) and on any run-level halt (FR-048). The exception carries a partial {@see RunReport}.
     * @throws \OutOfBoundsException When `$migrationId` is not registered. This is a programmer error (callers must validate against the registry first); the CLI surface catches and renders it.
     */
    public function run(string $migrationId, RunOptions $options): RunReport
    {
        return $this->runInternal(
            migrationId: $migrationId,
            options: $options,
            resumeFromPosition: 0,
            overrideRunId: null,
        );
    }

    /**
     * Resume the most recent run of a migration from its last completed
     * checkpoint, reusing the prior `run_id` so the second physical
     * invocation is part of the same logical run.
     *
     * Requires {@see MigrationRunState} to be injected — without it there is
     * no checkpoint to read.
     *
     * Source-plugin contract for FR-037: the source's `records()` iteration
     * order MUST be stable across invocations (deterministic given the same
     * configuration). The runner skips the first N records (where N is the
     * prior `MAX(position)`) by ordinal, not by source-id lookup, so a
     * non-deterministic source would silently re-process or skip records.
     * Sources that cannot guarantee stable order must not be marked
     * resume-safe (a `resumeSafe()` capability flag lands with WP10).
     *
     * @throws \InvalidArgumentException When no prior run exists for the migration, or {@see MigrationRunState} is not wired.
     * @throws MigrationAbortedException Same conditions as {@see run()}.
     *
     * @spec FR-037 — resume from the prior run's checkpoint
     */
    public function runResume(string $migrationId, RunOptions $options): RunReport
    {
        if ($this->runState === null) {
            throw new \InvalidArgumentException(
                'MigrationRunner::runResume(): MigrationRunState collaborator is required for resume mode '
                . '(inject it via the constructor).',
            );
        }

        $priorRunId = $this->runState->latestRunForMigration($migrationId);
        if ($priorRunId === null) {
            throw new \InvalidArgumentException(\sprintf(
                'MigrationRunner::runResume(): no prior run recorded for migration "%s"; '
                . 'run `import:run %s` first.',
                $migrationId,
                $migrationId,
            ));
        }

        $priorPosition = $this->runState->latestPositionForRun($migrationId, $priorRunId) ?? 0;

        return $this->runInternal(
            migrationId: $migrationId,
            options: $options,
            resumeFromPosition: $priorPosition,
            overrideRunId: $priorRunId,
        );
    }

    /**
     * Shared run body for {@see run()} and {@see runResume()}.
     *
     * `$resumeFromPosition`: how many source records to skip from the head of
     * iteration before any work happens. `0` means "fresh start" — the normal
     * `run()` path. Non-zero values come from {@see runResume()}.
     *
     * `$overrideRunId`: when non-null, suppresses the per-invocation UUIDv7
     * mint and reuses the prior run's id (FR-037 — single logical run across
     * physical invocations).
     *
     * @todo D-26 (tech-debt): this method is large (~170 LOC). The per-record
     *   body ({@see processOne()}) and the best-effort state writes
     *   ({@see safeHeartbeat()}, {@see safeRecordError()}, {@see safeSourceCount()})
     *   are already extracted. The remaining bulk is the FR-046/FR-047/FR-048
     *   error-handling dichotomy plus the two inline {@see RunReport}
     *   constructions in the catch arms. A future pass could lift the iteration
     *   loop and report assembly into a dedicated run-executor collaborator;
     *   deferred deliberately because it rewrites the runner's safety-critical
     *   halt/continue control flow for no behavioral change.
     */
    private function runInternal(
        string $migrationId,
        RunOptions $options,
        int $resumeFromPosition,
        ?string $overrideRunId,
    ): RunReport {
        $this->ensureContentModelsRegistered();

        $definition = $this->registry->get($migrationId);
        $runId = $overrideRunId ?? $options->runId ?? Uuid::v7()->toRfc4122();
        $startedAt = ($this->clock)();

        $lookup = $this->buildLookupClosure();
        $destination = $this->stampRunId($definition->destination, $runId);

        // Counters and per-record error list — mutated as the iteration progresses.
        $counters = ['imported' => 0, 'skipped' => 0, 'failed' => 0];
        /** @var list<RecordError> $errors */
        $errors = [];

        $total = $this->safeSourceCount($definition);
        $processed = 0;
        // FR-038 — `position` is the monotonic per-record counter persisted
        // into `migration_run_state`. Humans count from 1; on resume we
        // continue past the prior `MAX(position)`.
        $position = $resumeFromPosition;

        // The outer try/catch handles run-level errors (FR-048 — always halt).
        // The per-record try/catch inside the foreach handles typed per-record
        // exceptions (FR-046 — continue unless halt-on-error).
        try {
            $iterationIndex = 0;
            foreach ($this->iterateSource($definition) as $record) {
                // FR-037 — resume skip: discard records before the prior
                // checkpoint without touching the destination. Counts toward
                // neither imported/skipped/failed nor the progress table —
                // the prior run already recorded them.
                if ($iterationIndex < $resumeFromPosition) {
                    $iterationIndex++;
                    continue;
                }
                $iterationIndex++;

                if ($options->limit !== null && $processed >= $options->limit) {
                    break;
                }
                $processed++;
                $position++;

                try {
                    [$sourceIdHash, $outcome] = $this->processOne(
                        definition: $definition,
                        record: $record,
                        destination: $destination,
                        runId: $runId,
                        lookup: $lookup,
                        dryRun: $options->dryRun,
                        counters: $counters,
                    );

                    // FR-038 — heartbeat the per-record outcome. Best-effort:
                    // a failure here is logged but does NOT escalate the run
                    // to abort (the primary work — the destination write —
                    // already committed).
                    if ($this->runState !== null) {
                        $this->safeHeartbeat(
                            migrationId: $definition->id,
                            sourceIdHash: $sourceIdHash,
                            runId: $runId,
                            position: $position,
                            outcome: $outcome,
                        );
                    }
                } catch (SourceReadException | ProcessException | DestinationWriteException $e) {
                    $counters['failed']++;
                    $this->captureError($errors, $e, $record);

                    // FR-038 — record the failure into `migration_run_state`
                    // so `import:status` shows a non-zero FAILED column.
                    if ($this->runState !== null) {
                        $this->safeRecordError(
                            migrationId: $definition->id,
                            record: $record,
                            runId: $runId,
                            position: $position,
                            error: $e,
                        );
                    }

                    $this->logger->warning('MigrationRunner: per-record failure', [
                        'migration_id' => $definition->id,
                        'run_id' => $runId,
                        'error_class' => $e::class,
                        'error_code' => $this->codeFor($e),
                        'message' => $e->getMessage(),
                    ]);

                    if ($options->haltOnError) {
                        $finishedAt = $this->nowClampedTo($startedAt);
                        $report = new RunReport(
                            migrationId: $definition->id,
                            runId: $runId,
                            total: $total,
                            imported: $counters['imported'],
                            skipped: $counters['skipped'],
                            failed: $counters['failed'],
                            errors: $errors,
                            startedAt: $startedAt,
                            finishedAt: $finishedAt,
                            aborted: true,
                        );

                        throw new MigrationAbortedException(
                            report: $report,
                            reason: \sprintf(
                                'halt-on-error: first per-record failure (%s).',
                                $this->codeFor($e),
                            ),
                            previous: $e,
                        );
                    }
                }
            }
        } catch (MigrationAbortedException $e) {
            // Re-throw the halt-on-error report we just built.
            throw $e;
        } catch (\Throwable $e) {
            // FR-048 — run-level failure. Build a partial report and halt.
            $finishedAt = $this->nowClampedTo($startedAt);
            $report = new RunReport(
                migrationId: $definition->id,
                runId: $runId,
                total: $total,
                imported: $counters['imported'],
                skipped: $counters['skipped'],
                failed: $counters['failed'],
                errors: $errors,
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                aborted: true,
            );

            $this->logger->error('MigrationRunner: run-level abort (FR-048)', [
                'migration_id' => $definition->id,
                'run_id' => $runId,
                'error_class' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw new MigrationAbortedException(
                report: $report,
                reason: \sprintf('run-level failure (%s): %s', $e::class, $e->getMessage()),
                previous: $e,
            );
        }

        $finishedAt = $this->nowClampedTo($startedAt);

        return new RunReport(
            migrationId: $definition->id,
            runId: $runId,
            total: $total,
            imported: $counters['imported'],
            skipped: $counters['skipped'],
            failed: $counters['failed'],
            errors: $errors,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            aborted: false,
        );
    }

    /**
     * Capture the current instant from the injected clock and clamp it
     * monotonically against `$startedAt`.
     *
     * Wall-clock time is non-monotonic (NTP step, VM/WSL suspend-resume,
     * leap-second smear); a backward step between the `$startedAt` capture
     * (run() entry) and a `$finishedAt` capture below would otherwise trip
     * {@see RunReport}'s `finishedAt >= startedAt` invariant and crash the
     * CLI on a migration whose data is already fully committed. Advancing
     * the finish stamp by 1µs over the start mirrors
     * {@see RollbackWalker::rollback()}'s existing clamp.
     */
    private function nowClampedTo(\DateTimeImmutable $startedAt): \DateTimeImmutable
    {
        $finishedAt = ($this->clock)();

        return $finishedAt < $startedAt ? $startedAt->modify('+1 microsecond') : $finishedAt;
    }

    /**
     * Iterate the source plugin's records, wrapping any throw from the
     * underlying generator's `current()` or `next()` in a
     * {@see SourceReadException} so the runner's per-record try/catch can
     * decide between record-scoped capture and run-level halt.
     *
     * The plugin contract declares `records(): iterable<SourceRecord>`;
     * static analysis trusts that signature, so an in-band non-SourceRecord
     * value would surface as a TypeError at the per-record `processOne()`
     * call site rather than here. That TypeError is then caught by the
     * outer FR-048 catch and rewrapped as `MigrationAbortedException`.
     *
     * @return \Generator<int, SourceRecord>
     */
    private function iterateSource(MigrationDefinition $definition): \Generator
    {
        try {
            yield from $definition->source->records();
        } catch (SourceReadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // R2 mitigation: a generator's exception short-circuits the
            // outer foreach in run(). Rewrap as SourceReadException so the
            // run-level catch sees a typed signal. (`source_mid_iteration`
            // is a stable code consumers can pattern-match on.)
            throw new SourceReadException(
                sourceId: $definition->source->id(),
                migrationId: $definition->id,
                reason: \sprintf(
                    'source plugin threw mid-iteration (%s): %s',
                    $e::class,
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }
    }

    /**
     * Per-record body. Mutates `$counters` in place. Returns a
     * `[sourceIdHash, outcome]` tuple on success so the caller can heartbeat
     * the per-record outcome into `migration_run_state` (FR-038).
     *
     * `$outcome` is one of `'imported'` / `'skipped'`; the failure path
     * raises a typed exception and never returns.
     *
     * @param array{imported:int,skipped:int,failed:int} $counters
     *
     * @return array{0: string, 1: string} `[sourceIdHash, outcome]`.
     */
    private function processOne(
        MigrationDefinition $definition,
        SourceRecord $record,
        DestinationPluginInterface $destination,
        string $runId,
        \Closure $lookup,
        bool $dryRun,
        array &$counters,
    ): array {
        try {
            $sourceId = $definition->source->sourceIdFor($record);
        } catch (SourceReadException $e) {
            // Plugin already typed the failure; surface unchanged.
            throw $e;
        } catch (\Throwable $e) {
            throw new SourceReadException(
                sourceId: $definition->source->id(),
                migrationId: $definition->id,
                reason: \sprintf('sourceIdFor() threw (%s): %s', $e::class, $e->getMessage()),
                previous: $e,
            );
        }

        // Process chain — one pass per destination field. The process-map keys
        // are guaranteed strings by MigrationDefinition's per-instance validator.
        /** @var array<string, mixed> $values */
        $values = [];
        foreach (\array_keys($definition->process) as $destinationField) {
            $values[$destinationField] = $this->chain->executeField(
                $definition,
                $destinationField,
                $record,
                $lookup,
            );
        }

        $destinationRecord = new DestinationRecord(
            migrationId: $definition->id,
            sourceId: $sourceId,
            values: $values,
            bundle: $definition->bundle,
        );

        if ($dryRun) {
            // FR-039 — process steps executed; destination write skipped; counts
            // toward the "skipped" tally so operators see what would have been
            // imported.
            $counters['skipped']++;
            $this->logger->debug('MigrationRunner: dry-run skip', [
                'migration_id' => $definition->id,
                'run_id' => $runId,
                'source_id_hash' => $sourceId->hash(),
            ]);

            return [$sourceId->hash(), 'skipped'];
        }

        // FR-031 — pre-read the prior id-map row so we can distinguish the
        // hash-match skip path (still counts as a "skipped" record) from the
        // create-or-update path (counts as "imported"). EntityDestination
        // also performs this read internally; the small extra SELECT keeps
        // the counter accurate without leaking the destination's internals.
        $prior = $this->idMap->lookupDestination($definition->id, $sourceId);

        $writeResult = $destination->write($destinationRecord);

        $wasSkip = $prior !== null
            && $writeResult->destinationUuid === $prior->destinationUuid
            && $writeResult->sourceRecordHash === $prior->sourceRecordHash
            && $writeResult->runId === $prior->runId;

        if ($wasSkip) {
            $counters['skipped']++;
            return [$sourceId->hash(), 'skipped'];
        }

        $counters['imported']++;
        return [$sourceId->hash(), 'imported'];
    }

    /**
     * Best-effort heartbeat to `migration_run_state` after a successful
     * `processOne`. The destination write already committed; a failure in
     * the progress table must not roll back the primary work, so we swallow
     * + log any throw.
     *
     * `$outcome` is the classification returned by {@see processOne()}:
     *  - `'imported'` — destination write committed; recorded as `success`.
     *  - `'skipped'` — idempotent hash-match (FR-031) or dry-run (FR-039);
     *    recorded as `skipped`.
     */
    private function safeHeartbeat(
        string $migrationId,
        string $sourceIdHash,
        string $runId,
        int $position,
        string $outcome,
    ): void {
        \assert($this->runState !== null);

        try {
            $now = ($this->clock)();

            if ($outcome === 'skipped') {
                $this->runState->recordSkipped(
                    migrationId: $migrationId,
                    sourceIdHash: $sourceIdHash,
                    runId: $runId,
                    position: $position,
                    now: $now,
                );
                return;
            }

            $this->runState->recordSuccess(
                migrationId: $migrationId,
                sourceIdHash: $sourceIdHash,
                runId: $runId,
                position: $position,
                now: $now,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'MigrationRunner: migration_run_state heartbeat failed (non-fatal)',
                [
                    'migration_id' => $migrationId,
                    'run_id' => $runId,
                    'position' => $position,
                    'outcome' => $outcome,
                    'error_class' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Best-effort persistence of a per-record failure to `migration_run_state`.
     *
     * Wrapped in try/catch so a flaky state store does not turn a per-record
     * failure into a run-level abort (FR-046 + FR-038 — independent
     * concerns).
     */
    private function safeRecordError(
        string $migrationId,
        SourceRecord $record,
        string $runId,
        int $position,
        \Throwable $error,
    ): void {
        \assert($this->runState !== null);

        try {
            // Best-effort source-id hash — when the failure is upstream of
            // `sourceIdFor()`, fall back to a sentinel so the row is still
            // typed. This mirrors `captureError()`'s fallback.
            $sourceIdHash = 'unknown';
            try {
                $sourceIdHash = \hash(
                    'sha256',
                    \json_encode($record->fields, \JSON_THROW_ON_ERROR),
                );
            } catch (\Throwable) {
                // keep sentinel
            }

            $this->runState->recordError(
                migrationId: $migrationId,
                sourceIdHash: $sourceIdHash,
                runId: $runId,
                position: $position,
                errorCode: $this->codeFor($error),
                errorMessage: $error->getMessage(),
                now: ($this->clock)(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'MigrationRunner: migration_run_state error-record failed (non-fatal)',
                [
                    'migration_id' => $migrationId,
                    'run_id' => $runId,
                    'position' => $position,
                    'error_class' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Return the destination plugin used by `$definition`, stamping it with
     * the run id when it supports the runner contract.
     *
     * Falls back to the definition's destination unchanged for plugins that
     * predate {@see EntityDestination::withRunId()} — they generate per-write
     * run ids per the WP05 contract. (A future WP could extract `WithRunId`
     * to a plugin-level interface; today only `EntityDestination` ships.)
     */
    private function stampRunId(DestinationPluginInterface $destination, string $runId): DestinationPluginInterface
    {
        if ($destination instanceof EntityDestination) {
            return $destination->withRunId($runId);
        }

        // Third-party destinations: leave alone. Their `WriteResult::$runId`
        // is whatever they chose; the runner's report carries the runner-
        // minted id regardless of what the destination stamped per row.
        return $destination;
    }

    /**
     * Build the `$lookup` closure injected into every {@see \Waaseyaa\Migration\Plugin\ProcessContext}.
     *
     * The closure resolves `(migrationId, SourceId) -> ?WriteResult` by
     * delegating to {@see MigrationIdMap::lookupDestination()}. This
     * fulfills FR-028 from the process-plugin side: process plugins MUST
     * NOT touch `MigrationIdMap` directly — they go through the closure so
     * the runner owns transactional scoping and test-double injection.
     */
    private function buildLookupClosure(): \Closure
    {
        return function (string $migrationId, SourceId $sourceId): ?WriteResult {
            return $this->idMap->lookupDestination($migrationId, $sourceId);
        };
    }

    /**
     * Try to ask the source plugin for a count; coerce a `\Throwable` to
     * `-1` so a flaky pre-count does not abort the whole run.
     */
    private function safeSourceCount(MigrationDefinition $definition): int
    {
        try {
            $count = $definition->source->count();
        } catch (\Throwable $e) {
            $this->logger->warning('MigrationRunner: source->count() threw; treating as unknown', [
                'migration_id' => $definition->id,
                'error_class' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return -1;
        }

        return $count ?? -1;
    }

    /**
     * Append a per-record error to `$errors`, honoring the {@see RunReport::ERROR_CAP}.
     *
     * @param list<RecordError> $errors
     */
    private function captureError(array &$errors, \Throwable $e, SourceRecord $record): void
    {
        if (\count($errors) >= RunReport::ERROR_CAP) {
            return;
        }

        $stage = match (true) {
            $e instanceof SourceReadException => RecordError::STAGE_SOURCE,
            $e instanceof ProcessException => RecordError::STAGE_PROCESS,
            $e instanceof DestinationWriteException => RecordError::STAGE_DESTINATION,
            default => RecordError::STAGE_SOURCE,
        };

        // Best-effort source id hash — when the failure is upstream of
        // `sourceIdFor()`, fall back to a sentinel so the report stays
        // typed.
        $sourceIdHash = 'unknown';
        try {
            // SourceRecord has no SourceId yet (that requires the plugin's
            // key map). The best we can do is hash the record's field map.
            $sourceIdHash = \hash('sha256', \json_encode($record->fields, \JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // keep sentinel
        }

        $sourceField = $e instanceof ProcessException ? $e->sourceField : null;

        $errors[] = new RecordError(
            sourceIdHash: $sourceIdHash,
            code: $this->codeFor($e),
            message: $e->getMessage(),
            stage: $stage,
            sourceField: $sourceField,
        );
    }

    /**
     * Extract a stable string code from any of the three typed exceptions.
     */
    private function codeFor(\Throwable $e): string
    {
        return match (true) {
            $e instanceof ProcessException => $e->processCode,
            $e instanceof DestinationWriteException => $e->reason,
            $e instanceof SourceReadException => SourceReadException::CODE,
            default => 'UNKNOWN',
        };
    }
}
