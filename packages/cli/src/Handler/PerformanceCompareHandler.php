<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class PerformanceCompareHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $baselinePath = trim((string) ($io->option('baseline') ?? ''));
        $currentPath = trim((string) ($io->option('current') ?? ''));

        if ($baselinePath === '' || $currentPath === '') {
            $io->error('--baseline and --current are required.');

            return 2;
        }

        $baseline = $this->readJson($baselinePath);
        $current = $this->readJson($currentPath);

        if (!is_array($baseline) || !is_array($current)) {
            $io->error('Unable to read baseline/current JSON artifacts.');

            return 1;
        }

        $expectedHash = is_string($baseline['snapshot_hash'] ?? null) ? $baseline['snapshot_hash'] : '';
        $actualHash = is_string($current['snapshot_hash'] ?? null) ? $current['snapshot_hash'] : '';
        /** @var array<array-key, mixed> $thresholds */
        $thresholds = is_array($baseline['thresholds_ms'] ?? null) ? $baseline['thresholds_ms'] : [];
        /** @var array<array-key, mixed> $durations */
        $durations = is_array($current['durations_ms'] ?? null) ? $current['durations_ms'] : [];

        $violations = [];
        if ($expectedHash === '' || $actualHash === '' || $expectedHash !== $actualHash) {
            $violations[] = sprintf('snapshot_hash mismatch (expected %s, got %s)', $expectedHash, $actualHash);
        }

        foreach ($thresholds as $surface => $threshold) {
            if (!is_string($surface) || !is_numeric($threshold)) {
                continue;
            }
            if (!isset($durations[$surface]) || !is_numeric($durations[$surface])) {
                $violations[] = sprintf('missing duration for surface "%s"', $surface);
                continue;
            }

            $duration = (float) $durations[$surface];
            $limit = (float) $threshold;
            if ($duration > $limit) {
                $violations[] = sprintf('%s drifted: %.3fms > %.3fms', $surface, $duration, $limit);
            }
        }

        $result = [
            'status' => $violations === [] ? 'ok' : 'drift_detected',
            'baseline' => $baselinePath,
            'current' => $currentPath,
            'violation_count' => count($violations),
            'violations' => $violations,
        ];

        if ((bool) ($io->option('json') ?? false)) {
            $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif ($violations === []) {
            $io->writeln('Performance compare status: ok');
        } else {
            $io->writeln('Performance compare status: drift_detected');
            foreach ($violations as $violation) {
                $io->writeln('- ' . $violation);
            }
        }

        return $violations === [] ? 0 : 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $target_path): ?array
    {
        if (!is_file($target_path)) {
            return null;
        }

        $raw = file_get_contents($target_path);
        if (!is_string($raw)) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
