<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class SemanticWarmHandler
{
    public function __construct(
        private readonly SemanticIndexWarmer $warmer,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $entityTypes = $this->parseEntityTypeOption($io->option('type'));
        $limit = max(0, (int) ($io->option('limit') ?? 0));
        $report = $this->warmer->warm($entityTypes, $limit);

        if ((bool) $io->option('json')) {
            $io->writeln(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $io->writeln(sprintf('Semantic warm status: %s', $report['status']));
            $io->writeln(sprintf('Requested entity types: %s', implode(', ', $report['requested_entity_types'])));
            $io->writeln(sprintf('Processed: %d', $report['processed_total']));
            $io->writeln(sprintf('Stored: %d', $report['stored_total']));
            $io->writeln(sprintf('Removed: %d', $report['removed_total']));
            $io->writeln(sprintf('Missing: %d', $report['missing_total']));
            $io->writeln(sprintf('Duration: %.3fms', $report['duration_ms']));
        }

        return $report['status'] === 'skipped_no_provider' ? 1 : 0;
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
