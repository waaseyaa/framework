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
    name: 'semantic:refresh',
    description: 'Run resumable semantic index refresh batches',
)]
final class SemanticRefreshCommand extends Command
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
                'Entity type ID(s) to refresh (repeat option or pass comma-separated values)',
                ['node'],
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_REQUIRED,
                'Maximum entities per batch execution',
                '200',
            )
            ->addOption(
                'cursor',
                null,
                InputOption::VALUE_REQUIRED,
                'Resume cursor JSON (e.g. {"type_index":0,"offset":200})',
            )
            ->addOption(
                'until-complete',
                null,
                InputOption::VALUE_NONE,
                'Keep running batches until the refresh completes',
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Emit machine-readable JSON output',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entityTypes = $this->parseEntityTypeOption($input->getOption('type'));
        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $cursor = $this->parseCursorOption($input->getOption('cursor'));
        $untilComplete = (bool) $input->getOption('until-complete');

        $reports = [];
        do {
            $report = $this->warmer->warmBatch($entityTypes, $batchSize, $cursor);
            $reports[] = $report;
            $cursor = is_array($report['next_cursor'] ?? null) ? $report['next_cursor'] : null;
        } while ($untilComplete && $cursor !== null && ($report['status'] ?? '') !== 'skipped_no_provider');

        $final = $reports[count($reports) - 1] ?? null;
        if (!is_array($final)) {
            $output->writeln('<error>No refresh report generated.</error>');
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $payload = [
                'runs' => count($reports),
                'final' => $final,
                'reports' => $reports,
            ];
            $output->writeln(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $output->writeln(sprintf('Semantic refresh status: %s', $final['status']));
            $output->writeln(sprintf('Batch processed: %d', (int) ($final['batch_processed'] ?? 0)));
            $output->writeln(sprintf('Stored: %d', (int) ($final['stored_total'] ?? 0)));
            $output->writeln(sprintf('Removed: %d', (int) ($final['removed_total'] ?? 0)));
            $output->writeln(sprintf('Missing: %d', (int) ($final['missing_total'] ?? 0)));
            if (is_array($final['next_cursor'] ?? null)) {
                $output->writeln(sprintf(
                    'Next cursor: %s',
                    json_encode($final['next_cursor'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ));
            } else {
                $output->writeln('Next cursor: null (complete)');
            }
            $output->writeln(sprintf('Duration: %.3fms', (float) ($final['duration_ms'] ?? 0.0)));
        }

        return ($final['status'] ?? '') === 'skipped_no_provider' ? Command::FAILURE : Command::SUCCESS;
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

    /**
     * @return array{type_index?: int, offset?: int}|null
     */
    private function parseCursorOption(mixed $cursorOption): ?array
    {
        if (!is_string($cursorOption) || trim($cursorOption) === '') {
            return null;
        }

        try {
            $decoded = json_decode($cursorOption, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $cursor = [];
        if (isset($decoded['type_index']) && is_numeric($decoded['type_index'])) {
            $cursor['type_index'] = max(0, (int) $decoded['type_index']);
        }
        if (isset($decoded['offset']) && is_numeric($decoded['offset'])) {
            $cursor['offset'] = max(0, (int) $decoded['offset']);
        }

        return $cursor === [] ? null : $cursor;
    }
}
