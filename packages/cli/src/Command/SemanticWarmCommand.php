<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\AI\Vector\SemanticIndexWarmer;

#[AsCommand(
    name: 'semantic:warm',
    description: 'Warm semantic embeddings for deterministic read paths',
)]
final class SemanticWarmCommand extends Command
{
    public function __construct(
        private readonly SemanticIndexWarmer $warmer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Entity type ID(s) to warm (repeat option or pass comma-separated values)',
                ['node'],
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Per-type candidate limit (0 = no limit)',
                '0',
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Emit the full warming report as JSON',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityTypes = $this->parseEntityTypeOption($input->getOption('type'));
        $limit = max(0, (int) $input->getOption('limit'));
        $report = $this->warmer->warm($entityTypes, $limit);

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $output->writeln(sprintf('Semantic warm status: %s', $report['status']));
            $output->writeln(sprintf('Requested entity types: %s', implode(', ', $report['requested_entity_types'])));
            $output->writeln(sprintf('Processed: %d', $report['processed_total']));
            $output->writeln(sprintf('Stored: %d', $report['stored_total']));
            $output->writeln(sprintf('Removed: %d', $report['removed_total']));
            $output->writeln(sprintf('Missing: %d', $report['missing_total']));
            $output->writeln(sprintf('Duration: %.3fms', $report['duration_ms']));
        }

        return $report['status'] === 'skipped_no_provider' ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function parseEntityTypeOption(mixed $option): array
    {
        if (!is_array($option)) {
            return ['node'];
        }

        $types = [];
        foreach ($option as $value) {
            if (!is_string($value)) {
                continue;
            }

            foreach (explode(',', $value) as $piece) {
                $trimmed = trim($piece);
                if ($trimmed !== '') {
                    $types[] = $trimmed;
                }
            }
        }

        $types = array_values(array_unique($types));

        return $types === [] ? ['node'] : $types;
    }
}
