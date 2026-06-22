<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class SemanticRefreshHandler
{
    public function __construct(
        private readonly SemanticIndexWarmer $warmer,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $entityTypes = $this->parseEntityTypeOption($io->option('type'));
        $batchSize = max(1, (int) ($io->option('batch-size') ?? 200));
        $cursor = $this->parseCursorOption($io->option('cursor'));
        $untilComplete = (bool) $io->option('until-complete');

        $reports = [];
        do {
            $report = $this->warmer->warmBatch($entityTypes, $batchSize, $cursor);
            $reports[] = $report;
            $cursor = is_array($report['next_cursor'] ?? null) ? $report['next_cursor'] : null;
        } while ($untilComplete && $cursor !== null && ($report['status'] ?? '') !== 'skipped_no_provider');

        $final = $reports[count($reports) - 1] ?? null;
        if (!is_array($final)) {
            $io->error('No refresh report generated.');
            return 1;
        }

        if ((bool) $io->option('json')) {
            $payload = [
                'batch_count' => count($reports),
                'final' => $final,
                'reports' => $reports,
            ];
            $io->writeln(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $io->writeln(sprintf('Semantic refresh status: %s', $final['status'] ?? ''));
            $io->writeln(sprintf('Batches executed: %d', count($reports)));
            $io->writeln(sprintf('Processed: %d', $final['processed_total'] ?? 0));
            $io->writeln(sprintf('Stored: %d', $final['stored_total'] ?? 0));
            $io->writeln(sprintf('Removed: %d', $final['removed_total'] ?? 0));
            $io->writeln(sprintf('Missing: %d', $final['missing_total'] ?? 0));
            $io->writeln(sprintf('Duration: %.3fms', $final['duration_ms'] ?? 0.0));
            if ($cursor !== null) {
                $io->writeln(sprintf('Next cursor: %s', json_encode($cursor, JSON_THROW_ON_ERROR)));
            }
        }

        return ($final['status'] ?? '') === 'skipped_no_provider' ? 1 : 0;
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
