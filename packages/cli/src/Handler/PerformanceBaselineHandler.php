<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class PerformanceBaselineHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $contractVersion = trim((string) ($io->option('contract-version') ?? ''));
        $surface = trim((string) ($io->option('surface') ?? ''));
        $snapshotHash = trim((string) ($io->option('snapshot-hash') ?? ''));
        /** @var array<mixed> $rawThresholds */
        $rawThresholds = (array) ($io->option('threshold') ?? []);
        $thresholds = $this->parseThresholds($rawThresholds);

        if ($contractVersion === '' || $surface === '' || $snapshotHash === '') {
            $io->error('--contract-version, --surface, and --snapshot-hash are required.');

            return 2;
        }
        if ($thresholds === null || $thresholds === []) {
            $io->error('At least one valid --threshold surface:ms value is required.');

            return 2;
        }

        ksort($thresholds);
        $payload = [
            'contract_version' => $contractVersion,
            'surface' => $surface,
            'snapshot_hash' => $snapshotHash,
            'thresholds_ms' => $thresholds,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $outputPath = trim((string) ($io->option('output') ?? ''));

        if ($outputPath !== '') {
            $dir = dirname($outputPath);
            if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                $io->error(sprintf('Unable to create output directory: %s', $dir));

                return 1;
            }
            file_put_contents($outputPath, $json . PHP_EOL);
            $io->writeln(sprintf('Performance baseline written: %s', $outputPath));

            return 0;
        }

        $io->writeln($json);

        return 0;
    }

    /**
     * @param array<mixed> $rawThresholds
     * @return array<string, float>|null
     */
    private function parseThresholds(array $rawThresholds): ?array
    {
        $thresholds = [];
        foreach ($rawThresholds as $raw) {
            if (!is_string($raw)) {
                return null;
            }
            $parts = explode(':', $raw);
            if (count($parts) !== 2) {
                return null;
            }
            $surface = trim($parts[0]);
            $msRaw = trim($parts[1]);
            if ($surface === '' || !is_numeric($msRaw)) {
                return null;
            }
            $thresholds[$surface] = max(0.0, (float) $msRaw);
        }

        return $thresholds;
    }
}
