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
 * the canonical compiled-plan (`diff_hash`) to the ledger. Re-runs
 * compare the computed checksum against the stored one:
 *
 * - **Match:** node already applied, skip silently.
 * - **Mismatch + `isProduction: true`:** throw {@see ChecksumMismatchException}
 *   (`CHECKSUM_MISMATCH`). The same migration_id cannot mean two
 *   different structural intents.
 * - **Mismatch + `isProduction: false`:** log a warning via the
 *   optional {@see LoggerInterface} and skip the apply.
 * - **Stored checksum is null** (pre-WP09 row): the apply path does not invent
 *   historical evidence; strict verify reports the row as unverifiable.
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
            $this->repository->recordSourceCatalogFingerprint(
                MigrationCatalogFingerprint::capture($migrations, $v2Migrations),
            );
            $ordered = MigrationGraph::build($nodes)->topologicalOrder();
            $batch = $this->repository->getLastBatchNumber() + 1;
            $ran = [];

            foreach ($ordered as $node) {
                if ($this->repository->hasRun($node->id)) {
                    if ($node->kind === MigrationKind::V2) {
                        $this->guardV2Replay($node);
                    }
                    continue;
                }

                $this->applyNode($node, $batch, $policy);
                $ran[] = $node->id;
            }

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
            $flat = $this->flattenMigrations($migrations);
            foreach ($records as $record) {
                $name = $record['migration'];
                if (!isset($flat[$name])) {
                    throw new \RuntimeException(sprintf(
                        '[S1-DB103] Rollback refused: source migration "%s" is unavailable; schema and ledger remain unchanged.',
                        $name,
                    ));
                }
                $declaringClass = new \ReflectionMethod($flat[$name], 'down')->getDeclaringClass()->getName();
                if ($declaringClass === Migration::class) {
                    throw new \RuntimeException(sprintf(
                        '[S1-DB104] Rollback refused: migration "%s" has no declared reverse plan.',
                        $name,
                    ));
                }
            }

            foreach ($records as $record) {
                $name = $record['migration'];
                $this->connection->transactional(function () use ($flat, $name): void {
                    $schema = new SchemaBuilder($this->connection);
                    $flat[$name]->down($schema);
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
            $compiled = $executor->execute($plan, $policy);
            $this->repository->record($node->id, $node->package, $batch, $checksum, $compiled->diffHash());
        });
    }

    /**
     * Drift check for an already-applied v2 node: if the stored
     * checksum exists and differs from the computed one, throw in
     * production / warn in dev.
     */
    private function guardV2Replay(MigrationNode $node): void
    {
        $migration = $node->v2;
        if ($migration === null) {
            return;
        }

        $stored = $this->repository->getStoredChecksum($node->id);
        if ($stored === null) {
            // Pre-WP09 row or legacy migration — nothing to verify.
            return;
        }

        $computed = $migration->plan()->checksum();
        if ($stored === $computed) {
            return;
        }

        if ($this->isProduction) {
            throw new ChecksumMismatchException($node->id, $stored, $computed);
        }

        $this->logger?->warning(sprintf(
            'Migration "%s" stored checksum %s differs from computed %s. Skipping re-apply (dev mode). Set isProduction=true for strict refusal.',
            $node->id,
            $stored,
            $computed,
        ));
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     * @return array<string, Migration>
     */
    private function flattenMigrations(array $migrations): array
    {
        $flat = [];
        foreach ($migrations as $packageMigrations) {
            foreach ($packageMigrations as $name => $migration) {
                $flat[$name] = $migration;
            }
        }
        return $flat;
    }
}
