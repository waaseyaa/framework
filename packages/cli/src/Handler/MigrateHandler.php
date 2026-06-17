<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Migrate\DryRunFormatter;
use Waaseyaa\CLI\Command\Migrate\DryRunPlanner;
use Waaseyaa\CLI\Command\Migrate\OutputSanitizer;
use Waaseyaa\CLI\Command\Migrate\VerifyFormatter;
use Waaseyaa\CLI\Command\Migrate\VerifyRunner;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Diagnostic\DiagnosticCode;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;

/**
 * @api
 */
final class MigrateHandler
{
    /** @var \Closure(): array<string, array<string, \Waaseyaa\Foundation\Migration\Migration>> */
    private \Closure $migrationsProvider;

    /** @var \Closure(): list<MigrationInterfaceV2> */
    private \Closure $v2MigrationsProvider;

    /**
     * @param \Closure(): array<string, array<string, \Waaseyaa\Foundation\Migration\Migration>> $migrationsProvider
     * @param \Closure(): list<MigrationInterfaceV2>|null                                        $v2MigrationsProvider
     */
    public function __construct(
        private readonly Migrator $migrator,
        \Closure $migrationsProvider,
        ?\Closure $v2MigrationsProvider = null,
        private readonly ?MigrationRepository $repository = null,
        private readonly ?SqliteCompiler $compiler = null,
        private readonly bool $isProduction = true,
    ) {
        $this->migrationsProvider = $migrationsProvider;
        $this->v2MigrationsProvider = $v2MigrationsProvider ?? static fn(): array => [];
    }

    public function execute(SymfonyCommandIO $io): int
    {
        $dryRun = (bool) $io->option('dry-run');
        $verify = (bool) $io->option('verify');
        $json = (bool) $io->option('json');

        if ($dryRun && $verify) {
            $io->error(sprintf(
                '%s: --dry-run and --verify are mutually exclusive. Pass one or the other.',
                DiagnosticCode::INCOMPATIBLE_FLAGS->value,
            ));
            return 2;
        }

        if ($dryRun) {
            return $this->handleDryRun($io, $json);
        }
        if ($verify) {
            return $this->handleVerify($io, $json);
        }

        return $this->handleApply($io);
    }

    private function handleApply(SymfonyCommandIO $io): int
    {
        $migrations = ($this->migrationsProvider)();
        $v2 = ($this->v2MigrationsProvider)();
        $result = $this->migrator->run($migrations, $v2);

        if ($result->count === 0) {
            $io->writeln('Nothing to migrate.');
            return 0;
        }

        foreach ($result->migrations as $name) {
            $io->writeln("  Migrated: {$name}");
        }

        $label = $result->count === 1 ? 'migration' : 'migrations';
        $io->writeln("Ran {$result->count} {$label}.");

        return 0;
    }

    private function handleDryRun(SymfonyCommandIO $io, bool $json): int
    {
        if ($this->repository === null || $this->compiler === null) {
            $io->error('--dry-run requires a MigrationRepository and SqliteCompiler to be wired into MigrateHandler. See packages/foundation/src/Kernel/ConsoleKernel.php.');
            return 2;
        }

        $sanitizer = new OutputSanitizer($this->isProduction);
        $planner = new DryRunPlanner($this->repository, $this->compiler);
        $formatter = new DryRunFormatter($sanitizer);

        $result = $planner->plan(($this->migrationsProvider)(), ($this->v2MigrationsProvider)());

        $io->write($json ? $formatter->toJson($result) : $formatter->toText($result));

        return 0;
    }

    private function handleVerify(SymfonyCommandIO $io, bool $json): int
    {
        if ($this->repository === null) {
            $io->error('--verify requires a MigrationRepository to be wired into MigrateHandler. See packages/foundation/src/Kernel/ConsoleKernel.php.');
            return 2;
        }

        $sanitizer = new OutputSanitizer($this->isProduction);
        $runner = new VerifyRunner($this->repository);
        $formatter = new VerifyFormatter($sanitizer);

        $outcome = $runner->verify(($this->migrationsProvider)(), ($this->v2MigrationsProvider)());

        $io->write($json ? $formatter->toJson($outcome) : $formatter->toText($outcome));

        return $outcome->summary->hasFailure() ? 1 : 0;
    }
}
