<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\CLI\Provenance\ComposerProvenanceReporter;

#[AsCommand(
    name: 'waaseyaa:version',
    description: 'Print waaseyaa/* framework provenance (path SHA, lockfile versions, drift vs golden SHA)',
)]
final class WaaseyaaVersionCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable JSON')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail on drift when golden SHA is set (same as default; omit --report-only)')
            ->addOption('report-only', null, InputOption::VALUE_NONE, 'Print drift but always exit 0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = (new ComposerProvenanceReporter($this->projectRoot))->analyze();

        if ($input->getOption('json')) {
            $output->writeln(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            ComposerProvenanceReporter::printHumanToStream($report, $output);
        }

        if ($input->getOption('report-only')) {
            return Command::SUCCESS;
        }

        return $report->hasDrift() ? Command::FAILURE : Command::SUCCESS;
    }
}
