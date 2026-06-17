<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Command\Import\ImportResetCommand;
use Waaseyaa\CLI\Command\Import\ImportResumeCommand;
use Waaseyaa\CLI\Command\Import\ImportRollbackCommand;
use Waaseyaa\CLI\Command\Import\ImportRunAllCommand;
use Waaseyaa\CLI\Command\Import\ImportRunCommand;
use Waaseyaa\CLI\Command\Import\ImportStatusCommand;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\MigrationRunState;
use Waaseyaa\Migration\Runner\MigrationLock;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\RollbackWalker;

/**
 * Registers the `import:*` commands.
 *
 * - `import:run` / `import:run-all` / `import:status` ship with WP06.
 * - `import:resume` (FR-037) lands with WP07.
 * - `import:rollback` (FR-035) + `import:reset` (FR-036) land with WP08.
 * - All five mutating commands acquire a per-migration filesystem lock
 *   ({@see MigrationLock}) at command start; `import:status` is read-only
 *   and intentionally never touches the lock (WP09 / FR-061).
 *
 * Collaborator bindings ({@see MigrationRunner}, {@see RollbackWalker},
 * {@see MigrationRegistry}, {@see MigrationIdMap}, {@see MigrationRunState})
 * live in the migration package's own
 * {@see \Waaseyaa\Migration\ServiceProvider}; this provider only binds the
 * thin command handler classes (which the CLI kernel container resolves
 * via `[Class, method]` handler references) and yields the
 * {@see HandlerCommand}s.
 *
 * @spec FR-061 — per-migration concurrency lock wiring
 */
final class ImportServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    /**
     * Lock-file directory relative to the working directory.
     *
     * Per spec §9.3 (decision D11). When `getcwd()` cannot be resolved we
     * fall back to the system temp dir — better than crashing the command
     * over a missing storage tree.
     */
    private const string LOCK_DIR_RELATIVE = 'storage/migration-locks';

    public function register(): void
    {
        $lockFactory = $this->buildLockFactory();

        $this->singleton(ImportRunCommand::class, function () use ($lockFactory): ImportRunCommand {
            $runner = $this->resolve(MigrationRunner::class);
            \assert($runner instanceof MigrationRunner);
            $registry = $this->resolve(MigrationRegistry::class);
            \assert($registry instanceof MigrationRegistry);

            return new ImportRunCommand($runner, $registry, $lockFactory);
        });

        $this->singleton(ImportRunAllCommand::class, function () use ($lockFactory): ImportRunAllCommand {
            $runner = $this->resolve(MigrationRunner::class);
            \assert($runner instanceof MigrationRunner);
            $registry = $this->resolve(MigrationRegistry::class);
            \assert($registry instanceof MigrationRegistry);

            return new ImportRunAllCommand($runner, $registry, $lockFactory);
        });

        $this->singleton(ImportStatusCommand::class, function (): ImportStatusCommand {
            $registry = $this->resolve(MigrationRegistry::class);
            \assert($registry instanceof MigrationRegistry);
            $idMap = $this->resolve(MigrationIdMap::class);
            \assert($idMap instanceof MigrationIdMap);
            $runState = $this->resolve(MigrationRunState::class);
            \assert($runState instanceof MigrationRunState);

            // FR-061: import:status is intentionally read-only and does
            // NOT acquire the lock. Concurrent invocations are safe.
            return new ImportStatusCommand($registry, $idMap, $runState);
        });

        $this->singleton(ImportResumeCommand::class, function () use ($lockFactory): ImportResumeCommand {
            $runner = $this->resolve(MigrationRunner::class);
            \assert($runner instanceof MigrationRunner);
            $registry = $this->resolve(MigrationRegistry::class);
            \assert($registry instanceof MigrationRegistry);

            return new ImportResumeCommand($runner, $registry, $lockFactory);
        });

        $this->singleton(ImportRollbackCommand::class, function () use ($lockFactory): ImportRollbackCommand {
            $walker = $this->resolve(RollbackWalker::class);
            \assert($walker instanceof RollbackWalker);
            $registry = $this->resolve(MigrationRegistry::class);
            \assert($registry instanceof MigrationRegistry);
            $idMap = $this->resolve(MigrationIdMap::class);
            \assert($idMap instanceof MigrationIdMap);

            return new ImportRollbackCommand($walker, $registry, $idMap, $lockFactory);
        });

        $this->singleton(ImportResetCommand::class, function () use ($lockFactory): ImportResetCommand {
            $registry = $this->resolve(MigrationRegistry::class);
            \assert($registry instanceof MigrationRegistry);
            $idMap = $this->resolve(MigrationIdMap::class);
            \assert($idMap instanceof MigrationIdMap);
            $runState = $this->resolve(MigrationRunState::class);
            \assert($runState instanceof MigrationRunState);

            return new ImportResetCommand($registry, $idMap, $runState, $lockFactory);
        });
    }

    /**
     * Build the shared `\Closure(string $migrationId): MigrationLock`
     * factory passed to every mutating import command.
     *
     * Resolves the logger lazily on each invocation so kernel-late
     * logger swaps (e.g. CLI quiet / verbose flags) reach the lock.
     *
     * @return \Closure(string): MigrationLock
     */
    private function buildLockFactory(): \Closure
    {
        return function (string $migrationId): MigrationLock {
            $cwd = \getcwd();
            $base = \is_string($cwd) ? $cwd : \sys_get_temp_dir();
            $lockDir = $base . \DIRECTORY_SEPARATOR . self::LOCK_DIR_RELATIVE;

            $logger = $this->resolveLogger();

            return new MigrationLock(
                migrationId: $migrationId,
                lockDir: $lockDir,
                logger: $logger,
            );
        };
    }

    private function resolveLogger(): ?LoggerInterface
    {
        try {
            $logger = $this->resolve(LoggerInterface::class);
        } catch (\Throwable) {
            return null;
        }
        return $logger instanceof LoggerInterface ? $logger : null;
    }

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'import:run',
            description: 'Run a single migration end-to-end (FR-032).',
            arguments: [
                new HandlerArgument(
                    name: 'migration_id',
                    mode: HandlerArgumentMode::Required,
                    description: 'Id of the migration to execute (e.g. wp_users_to_accounts).',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Execute source + process steps; skip destination writes (FR-039).',
                ),
                new HandlerOption(
                    name: 'halt-on-error',
                    mode: HandlerOptionMode::None,
                    description: 'Halt on the first per-record error (FR-047).',
                ),
                new HandlerOption(
                    name: 'limit',
                    mode: HandlerOptionMode::Required,
                    description: 'Process only the first N source records (FR-040).',
                ),
                new HandlerOption(
                    name: 'run-id',
                    mode: HandlerOptionMode::Required,
                    description: 'Override the generated UUIDv7 run id (advanced; CI/testing).',
                ),
            ],
            handler: [ImportRunCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'import:run-all',
            description: 'Run every registered migration in dependency order (FR-033).',
            options: [
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Execute source + process steps; skip destination writes (FR-039).',
                ),
                new HandlerOption(
                    name: 'halt-on-error',
                    mode: HandlerOptionMode::None,
                    description: 'Halt on the first per-record error in any migration (FR-047).',
                ),
                new HandlerOption(
                    name: 'limit',
                    mode: HandlerOptionMode::Required,
                    description: 'Per-migration record cap (FR-040).',
                ),
            ],
            handler: [ImportRunAllCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'import:status',
            description: 'Report per-migration import state (FR-034).',
            arguments: [
                new HandlerArgument(
                    name: 'migration_id',
                    mode: HandlerArgumentMode::Optional,
                    description: 'Optional migration id to filter on; default lists all migrations.',
                ),
            ],
            handler: [ImportStatusCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'import:resume',
            description: 'Resume the most recent run of one migration (FR-037).',
            arguments: [
                new HandlerArgument(
                    name: 'migration_id',
                    mode: HandlerArgumentMode::Required,
                    description: 'Id of the migration to resume.',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Execute source + process steps; skip destination writes (FR-039).',
                ),
                new HandlerOption(
                    name: 'halt-on-error',
                    mode: HandlerOptionMode::None,
                    description: 'Halt on the first per-record error (FR-047).',
                ),
                new HandlerOption(
                    name: 'limit',
                    mode: HandlerOptionMode::Required,
                    description: 'Process only the next N source records (FR-040).',
                ),
            ],
            handler: [ImportResumeCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'import:rollback',
            description: 'Undo every previously-written record for one migration (FR-035).',
            arguments: [
                new HandlerArgument(
                    name: 'migration_id',
                    mode: HandlerArgumentMode::Required,
                    description: 'Id of the migration to roll back.',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'confirm',
                    mode: HandlerOptionMode::None,
                    description: 'Required to proceed; destructive operation gate.',
                ),
            ],
            handler: [ImportRollbackCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'import:reset',
            description: 'Clear the id-map and run-state without touching destination entities (FR-036).',
            arguments: [
                new HandlerArgument(
                    name: 'migration_id',
                    mode: HandlerArgumentMode::Required,
                    description: 'Id of the migration whose import history will be cleared.',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'confirm',
                    mode: HandlerOptionMode::None,
                    description: 'Required to proceed; destructive operation gate.',
                ),
            ],
            handler: [ImportResetCommand::class, 'execute'],
        );
    }
}
