<?php

declare(strict_types=1);

namespace Aurora\Foundation\Migration;

use Doctrine\DBAL\Connection;

final class Migrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationRepository $repository,
    ) {}

    /**
     * @param array<string, array<string, Migration>> $migrations package => [name => Migration]
     */
    public function run(array $migrations): MigrationResult
    {
        $ordered = $this->resolveDependencyOrder($migrations);
        $batch = $this->repository->getLastBatchNumber() + 1;
        $ran = [];

        foreach ($ordered as ['package' => $package, 'name' => $name, 'migration' => $migration]) {
            if ($this->repository->hasRun($name)) {
                continue;
            }

            $schema = new SchemaBuilder($this->connection);
            $migration->up($schema);
            $this->repository->record($name, $package, $batch);
            $ran[] = $name;
        }

        return new MigrationResult(count($ran), $ran);
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     */
    public function rollback(array $migrations): MigrationResult
    {
        $batch = $this->repository->getLastBatchNumber();
        if ($batch === 0) {
            return new MigrationResult(0);
        }

        $records = $this->repository->getByBatch($batch);
        $flat = $this->flattenMigrations($migrations);
        $rolledBack = [];

        foreach ($records as $record) {
            $name = $record['migration'];
            if (isset($flat[$name])) {
                $schema = new SchemaBuilder($this->connection);
                $flat[$name]->down($schema);
            }
            $this->repository->remove($name);
            $rolledBack[] = $name;
        }

        return new MigrationResult(count($rolledBack), $rolledBack);
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     * @return array{pending: list<string>, completed: list<string>}
     */
    public function status(array $migrations): array
    {
        $completed = $this->repository->getCompleted();
        $all = array_keys($this->flattenMigrations($migrations));
        $pending = array_values(array_diff($all, $completed));

        return ['pending' => $pending, 'completed' => $completed];
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     * @return list<array{package: string, name: string, migration: Migration}>
     */
    private function resolveDependencyOrder(array $migrations): array
    {
        // Topological sort: packages with no $after run first
        $packageOrder = $this->topologicalSort($migrations);

        $ordered = [];
        foreach ($packageOrder as $package) {
            foreach ($migrations[$package] ?? [] as $name => $migration) {
                $ordered[] = ['package' => $package, 'name' => $name, 'migration' => $migration];
            }
        }

        return $ordered;
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     * @return list<string> package names in dependency order
     */
    private function topologicalSort(array $migrations): array
    {
        $packages = array_keys($migrations);
        $deps = [];

        foreach ($migrations as $package => $packageMigrations) {
            $deps[$package] = [];
            foreach ($packageMigrations as $migration) {
                foreach ($migration->after as $dep) {
                    if (in_array($dep, $packages, true)) {
                        $deps[$package][] = $dep;
                    }
                }
            }
            $deps[$package] = array_unique($deps[$package]);
        }

        $sorted = [];
        $visited = [];

        $visit = function (string $package) use (&$visit, &$sorted, &$visited, $deps): void {
            if (isset($visited[$package])) {
                return;
            }
            $visited[$package] = true;

            foreach ($deps[$package] ?? [] as $dep) {
                $visit($dep);
            }

            $sorted[] = $package;
        };

        foreach ($packages as $package) {
            $visit($package);
        }

        return $sorted;
    }

    /**
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
