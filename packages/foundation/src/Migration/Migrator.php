<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

use Doctrine\DBAL\Connection;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Migration\Dag\MigrationGraph;
use Waaseyaa\Foundation\Migration\Dag\MigrationKind;
use Waaseyaa\Foundation\Migration\Dag\MigrationNode;
use Waaseyaa\Foundation\Migration\Executor\V2PlanExecutor;
use Waaseyaa\Foundation\Schema\Compiler\Validation\PlanPolicy;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;

/**
 * Applies pending migrations.
 *
 * Post-WP06, the Migrator routes both legacy and v2 migrations through a
 * single {@see MigrationGraph}: one batch per `run()`, one outer transaction
 * for the complete ordered plan, deterministic order via Q4's `(package ASC, id ASC)`
 * tie-break. Empty v2 plans write a ledger row and execute zero SQL.
 *
 * **Post-WP09 ledger discipline.** Every successful v2 apply writes the
 * SHA-256 of the canonical SchemaDiff (`checksum`) and the SHA-256 of
 * the canonical compiled-plan (`diff_hash`) to the ledger. Both are
 * functions of the authored plan alone, so they are identical whether the
 * node executed SQL or found the schema already satisfied (FW-2701); the
 * nullable `apply_mode` column carries that distinction as audit evidence
 * and is never consulted here. Re-runs recheck every already-applied node —
 * legacy and v2 alike — against its ledger row (#2730):
 *
 * - **Package identity** must match the row's `package`, and the stored
 *   `diff_hash` must match the plan this run would record (the compiled plan
 *   for v2, the domain-separated procedural hash for legacy). A mismatch is
 *   refused with `[S1-DB112]` in production and logged as a warning in dev.
 * - **Source checksum match:** node already applied, skip silently.
 * - **Checksum mismatch + `isProduction: true`:** throw {@see ChecksumMismatchException}
 *   (`CHECKSUM_MISMATCH`). The same migration_id cannot mean two
 *   different structural intents.
 * - **Checksum mismatch + `isProduction: false`:** log a warning via the
 *   optional {@see LoggerInterface} and skip the apply.
 * - **Stored checksum is null** (pre-WP09 row): the apply path does not invent
 *   historical evidence; strict verify reports the row as unverifiable.
 *
 * The loaded catalogue fingerprint is recorded only after every replay check
 * has passed, so a refused replay cannot rewrite the catalogue authority.
 * Live schema and ledger pre-state are validated by the coordinator before
 * any of this runs.
 *
 * **Legacy rollback (#2731).** `rollback()` is fail-closed: every node in the
 * last batch must declare a supported reverse (`providesSupportedReverse()` or
 * {@see LegacyReversePlanCatalog}), match the applied package and source
 * checksum, and change the logical schema fingerprint before the ledger row
 * is removed. Unsupported, unverifiable, or ineffective reverses refuse with
 * `[S1-DB104]` / `[S1-DB113]` / `[S1-DB114]` and leave schema and ledger
 * unchanged. Missing source keeps `[S1-DB103]`. V2 nodes still have no reverse
 * contract and fail as missing legacy reverse source.
 *
 * **S1-FW-DB-02 failure semantics:** the repository acquires SQLite writer
 * authority before reading ledger state, and a SQL/compile/verification
 * failure rolls back every node and ledger effect in the requested plan.
 *
 * **Backward compatibility:** the legacy `run(array $migrations)` shape
 * still works. v2 callers pass a list of {@see MigrationInterfaceV2}
 * instances as the second argument; the optional third argument carries
 * a {@see PlanPolicy} for the destructive-op gate.
 */
final class Migrator
{
    private readonly SchemaMutationCoordinator $coordinator;

    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationRepository $repository,
        private readonly ?V2PlanExecutor $v2Executor = null,
        private readonly bool $isProduction = true,
        private readonly ?LoggerInterface $logger = null,
        ?SchemaMutationCoordinator $coordinator = null,
    ) {
        $this->coordinator = $coordinator ?? new SchemaMutationCoordinator($connection, $repository);
    }

    /**
     * @param array<string, array<string, Migration>> $migrations    package => [name => Migration]
     * @param list<MigrationInterfaceV2>              $v2Migrations  v2 migrations (optional)
     * @param PlanPolicy                              $policy        applied to every v2 plan in this run
     */
    public function run(
        array $migrations,
        array $v2Migrations = [],
        PlanPolicy $policy = new PlanPolicy(),
    ): MigrationResult {
        $nodes = $this->buildNodes($migrations, $v2Migrations);

        if ($v2Migrations !== [] && $this->v2Executor === null) {
            throw new \LogicException(
                'Migrator received v2 migrations but no V2PlanExecutor was injected. Construct the Migrator with a non-null V2PlanExecutor to enable the v2 dispatch path.',
            );
        }

        return $this->coordinator->execute(function () use ($migrations, $v2Migrations, $nodes, $policy): MigrationResult {
            $ordered = MigrationGraph::build($nodes)->topologicalOrder();
            $batch = $this->repository->getLastBatchNumber() + 1;
            $applied = [];
            foreach ($this->repository->allWithChecksums() as $row) {
                $applied[$row->migration] = $row;
            }
            $ran = [];

            foreach ($ordered as $node) {
                $row = $applied[$node->id] ?? null;
                if ($row !== null) {
                    $this->guardReplay($node, $row);
                    continue;
                }

                $this->applyNode($node, $batch, $policy);
                $ran[] = $node->id;
            }

            $this->repository->recordSourceCatalogFingerprint(
                MigrationCatalogFingerprint::capture($migrations, $v2Migrations),
            );

            return new MigrationResult(count($ran), $ran);
        });
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     */
    public function rollback(array $migrations): MigrationResult
    {
        $rolledBack = [];

        $this->coordinator->execute(function () use ($migrations, &$rolledBack): void {
            $batch = $this->repository->getLastBatchNumber();
            if ($batch === 0) {
                return;
            }

            $records = $this->repository->getByBatch($batch);
            $catalogue = $this->catalogueEntries($migrations);
            $ledgerById = [];
            foreach ($this->repository->allWithChecksums() as $row) {
                if ($row->batch === $batch) {
                    $ledgerById[$row->migration] = $row;
                }
            }

            foreach ($records as $record) {
                $name = $record['migration'];
                if (!isset($catalogue[$name])) {
                    throw new \RuntimeException(sprintf(
                        '[S1-DB103] Rollback refused: source migration "%s" is unavailable; schema and ledger remain unchanged.',
                        $name,
                    ));
                }

                $entry = $catalogue[$name];
                $migration = $entry['migration'];
                $package = $entry['package'];
                $ledger = $ledgerById[$name] ?? null;

                if (!$migration->providesSupportedReverse() && !LegacyReversePlanCatalog::allows($name)) {
                    throw new \RuntimeException(sprintf(
                        '[S1-DB104] Rollback refused: migration "%s" has no declared reverse plan.',
                        $name,
                    ));
                }

                if ($ledger === null || $ledger->checksum === null) {
                    throw new \RuntimeException(sprintf(
                        '[S1-DB113] Rollback refused: migration "%s" has no verifiable applied source checksum; schema and ledger remain unchanged.',
                        $name,
                    ));
                }

                if ($ledger->package !== $package) {
                    throw new \RuntimeException(sprintf(
                        '[S1-DB113] Rollback refused: migration "%s" was applied by package "%s" but the loaded catalogue declares it under "%s"; schema and ledger remain unchanged.',
                        $name,
                        $ledger->package,
                        $package,
                    ));
                }

                $loadedChecksum = MigrationCatalogFingerprint::legacySourceChecksum($migration);
                if ($ledger->checksum !== $loadedChecksum) {
                    throw new \RuntimeException(sprintf(
                        '[S1-DB113] Rollback refused: migration "%s" loaded source checksum %s does not match applied checksum %s; schema and ledger remain unchanged.',
                        $name,
                        $loadedChecksum,
                        $ledger->checksum,
                    ));
                }
            }

            foreach ($records as $record) {
                $name = $record['migration'];
                $migration = $catalogue[$name]['migration'];
                $this->connection->transactional(function () use ($migration, $name): void {
                    $before = $this->repository->currentLogicalSchemaFingerprint();
                    $schema = new SchemaBuilder($this->connection);
                    $migration->down($schema);
                    $after = $this->repository->currentLogicalSchemaFingerprint();
                    if ($before === $after) {
                        throw new \RuntimeException(sprintf(
                            '[S1-DB114] Rollback refused: migration "%s" reverse produced no verifiable schema post-state change; schema and ledger remain unchanged.',
                            $name,
                        ));
                    }
                    $this->repository->remove($name);
                });
                $rolledBack[] = $name;
            }
        });

        return new MigrationResult(count($rolledBack), $rolledBack);
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     * @param list<MigrationInterfaceV2>              $v2Migrations
     * @return array{pending: list<string>, completed: list<array{migration: string, package: string, batch: int}>}
     */
    public function status(array $migrations, array $v2Migrations = []): array
    {
        $completedDetails = $this->repository->getCompletedWithDetails();
        $completedNames = array_column($completedDetails, 'migration');

        $allIds = array_keys($this->flattenMigrations($migrations));
        foreach ($v2Migrations as $v2) {
            $allIds[] = $v2->migrationId();
        }

        $pending = array_values(array_diff($allIds, $completedNames));

        return ['pending' => $pending, 'completed' => $completedDetails];
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     * @param list<MigrationInterfaceV2>              $v2Migrations
     * @return list<MigrationNode>
     */
    private function buildNodes(array $migrations, array $v2Migrations): array
    {
        $nodes = [];

        foreach ($migrations as $package => $packageMigrations) {
            foreach ($packageMigrations as $name => $migration) {
                $nodes[] = MigrationNode::fromLegacy($name, $package, $migration);
            }
        }

        foreach ($v2Migrations as $v2) {
            $nodes[] = MigrationNode::fromV2($v2);
        }

        return $nodes;
    }

    private function applyNode(MigrationNode $node, int $batch, PlanPolicy $policy): void
    {
        match ($node->kind) {
            MigrationKind::Legacy => $this->applyLegacy($node, $batch),
            MigrationKind::V2 => $this->applyV2($node, $batch, $policy),
        };
    }

    private function applyLegacy(MigrationNode $node, int $batch): void
    {
        $migration = $node->legacy;
        if ($migration === null) {
            // MigrationNode invariants prevent this, but PHPStan needs the guard.
            throw new \LogicException(sprintf('Legacy node "%s" has no source migration.', $node->id));
        }

        $schema = new SchemaBuilder($this->connection);
        $this->connection->transactional(function () use ($migration, $schema, $node, $batch): void {
            $migration->up($schema);
            $sourceChecksum = MigrationCatalogFingerprint::legacySourceChecksum($migration);
            $this->repository->record(
                $node->id,
                $node->package,
                $batch,
                $sourceChecksum,
                MigrationCatalogFingerprint::legacyPlanHash($sourceChecksum),
            );
        });
    }

    private function applyV2(MigrationNode $node, int $batch, PlanPolicy $policy): void
    {
        $migration = $node->v2;
        $executor = $this->v2Executor;
        if ($migration === null || $executor === null) {
            throw new \LogicException(sprintf('V2 node "%s" cannot apply: missing source or executor.', $node->id));
        }

        $plan = $migration->plan();
        $checksum = $plan->checksum();

        $this->connection->transactional(function () use ($executor, $plan, $policy, $node, $batch, $checksum): void {
            // FW-2701: targeted materialization, precondition classification and
            // execution all happen inside this transaction alongside the ledger
            // write, so an interrupted initialization leaves the node wholly
            // applied or wholly absent.
            $outcome = $executor->execute($plan, $policy);
            if ($outcome->materializedTables !== []) {
                $this->logger?->info(sprintf(
                    'Migration "%s" materialized absent entity base table(s) before applying: %s.',
                    $node->id,
                    implode(', ', $outcome->materializedTables),
                ));
            }
            $this->repository->record(
                $node->id,
                $node->package,
                $batch,
                $checksum,
                $outcome->diffHash(),
                $outcome->mode->value,
            );
        });
    }

    /**
     * Recheck an already-applied node against its ledger row (#2730).
     *
     * Package identity and compiled-plan identity are refused with
     * `[S1-DB112]`; a source-checksum mismatch keeps its established
     * `CHECKSUM_MISMATCH` refusal. Both throw in production and warn in dev.
     * A null stored hash is a pre-WP09 row: nothing is invented, and strict
     * verification keeps reporting it as unverifiable.
     */
    private function guardReplay(MigrationNode $node, LedgerRow $row): void
    {
        if ($row->package !== $node->package) {
            $this->refuseReplay($node, sprintf(
                '[S1-DB112] Migration "%s" was applied by package "%s" but the loaded catalogue declares it under "%s".',
                $node->id,
                $row->package,
                $node->package,
            ));

            return;
        }

        if ($row->checksum === null) {
            return;
        }

        [$computedChecksum, $computedPlanHash] = match ($node->kind) {
            MigrationKind::Legacy => $this->legacyIdentity($node),
            MigrationKind::V2 => $this->v2Identity($node),
        };

        if ($row->checksum !== $computedChecksum) {
            if ($this->isProduction) {
                throw new ChecksumMismatchException($node->id, $row->checksum, $computedChecksum);
            }

            $this->logger?->warning(sprintf(
                'Migration "%s" stored checksum %s differs from computed %s. Skipping re-apply (dev mode). Set isProduction=true for strict refusal.',
                $node->id,
                $row->checksum,
                $computedChecksum,
            ));

            return;
        }

        if ($row->diffHash !== null && $row->diffHash !== $computedPlanHash) {
            $this->refuseReplay($node, sprintf(
                '[S1-DB112] Migration "%s" has stored compiled plan %s but the current source compiles to %s.',
                $node->id,
                $row->diffHash,
                $computedPlanHash,
            ));
        }
    }

    /** @return array{0: string, 1: string} source checksum and plan hash this run would record */
    private function legacyIdentity(MigrationNode $node): array
    {
        $migration = $node->legacy;
        if ($migration === null) {
            throw new \LogicException(sprintf('Legacy node "%s" has no source migration.', $node->id));
        }
        $checksum = MigrationCatalogFingerprint::legacySourceChecksum($migration);

        return [$checksum, MigrationCatalogFingerprint::legacyPlanHash($checksum)];
    }

    /** @return array{0: string, 1: string} source checksum and plan hash this run would record */
    private function v2Identity(MigrationNode $node): array
    {
        $migration = $node->v2;
        $executor = $this->v2Executor;
        if ($migration === null || $executor === null) {
            throw new \LogicException(sprintf('V2 node "%s" cannot be rechecked: missing source or executor.', $node->id));
        }
        $plan = $migration->plan();

        return [$plan->checksum(), $executor->compiledDiffHash($plan)];
    }

    private function refuseReplay(MigrationNode $node, string $message): void
    {
        if ($this->isProduction) {
            throw new \RuntimeException($message . ' Re-apply is refused in production; schema, ledger and catalogue remain unchanged.');
        }

        $this->logger?->warning(sprintf(
            '%s Skipping re-apply of "%s" (dev mode). Set isProduction=true for strict refusal.',
            $message,
            $node->id,
        ));
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     * @return array<string, Migration>
     */
    private function flattenMigrations(array $migrations): array
    {
        $flat = [];
        foreach ($this->catalogueEntries($migrations) as $name => $entry) {
            $flat[$name] = $entry['migration'];
        }
        return $flat;
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     * @return array<string, array{migration: Migration, package: string}>
     */
    private function catalogueEntries(array $migrations): array
    {
        $entries = [];
        foreach ($migrations as $package => $packageMigrations) {
            foreach ($packageMigrations as $name => $migration) {
                $entries[$name] = [
                    'migration' => $migration,
                    'package' => $package,
                ];
            }
        }

        return $entries;
    }
}
