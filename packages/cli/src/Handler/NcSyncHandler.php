<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\NorthCloud\Sync\NcSyncResult;
use Waaseyaa\NorthCloud\Sync\NcSyncService;

final class NcSyncHandler
{
    public function __construct(
        private readonly NcSyncService $syncService,
        private readonly ?string $statusPath = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $limit = (int) ($io->option('limit') ?? '20');
        $since = $io->option('since');
        $dryRun = (bool) $io->option('dry-run');
        $explain = (bool) $io->option('explain');
        $sample = max(0, (int) ($io->option('sample') ?? '10'));
        $reportJsonPath = $io->option('report-json');

        if ($dryRun) {
            $io->writeln('Dry run — no entities will be created.');
        }

        $io->writeln(sprintf('Fetching up to %d hits from NorthCloud...', $limit));

        $result = $this->syncService->sync(
            limit: $limit,
            since: is_string($since) ? $since : null,
            dryRun: $dryRun,
            explain: $explain,
            sampleLimit: $sample,
        );

        if ($result->fetchFailed) {
            $io->writeln('Failed to fetch content from NorthCloud. Check NORTHCLOUD_BASE_URL and network connectivity.');
            return 1;
        }

        $io->writeln(sprintf(
            'Done. Created: %d | Skipped: %d | Failed: %d',
            $result->created,
            $result->skipped,
            $result->failed,
        ));

        if ($explain && $result->skipReasons !== []) {
            $io->writeln('Skip reasons:');
            $skipReasons = $result->skipReasons;
            arsort($skipReasons);
            foreach ($skipReasons as $reason => $count) {
                $io->writeln(sprintf('  - %s: %d', $reason, $count));
            }
        }

        if ($result->createdSamples !== []) {
            $io->writeln('Created sample:');
            foreach ($result->createdSamples as $sampleRow) {
                $io->writeln('  - ' . $this->formatSampleLine($sampleRow));
            }
        }

        if ($result->skippedSamples !== []) {
            $io->writeln('Skipped sample:');
            foreach ($result->skippedSamples as $sampleRow) {
                $io->writeln('  - ' . $this->formatSampleLine($sampleRow));
            }
        }

        if (is_string($reportJsonPath) && $reportJsonPath !== '') {
            $this->writeJsonReport($reportJsonPath, $result, $limit, is_string($since) ? $since : null, $dryRun, $explain, $sample);
            $io->writeln(sprintf('Wrote report: %s', $reportJsonPath));
        }

        $this->writeStatusFile($result);

        return 0;
    }

    private function writeStatusFile(NcSyncResult $result): void
    {
        if ($this->statusPath === null) {
            return;
        }

        try {
            $data = json_encode([
                'last_sync' => date('c'),
                'created' => $result->created,
                'skipped' => $result->skipped,
                'failed' => $result->failed,
                'fetch_failed' => $result->fetchFailed,
                'cycles' => 1,
                'last_manual_run' => date('c'),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (\JsonException) {
            return;
        }

        $tmp = $this->statusPath . '.tmp';
        if (file_put_contents($tmp, $data) === false) {
            return;
        }
        rename($tmp, $this->statusPath);
    }

    /**
     * @param array<string, mixed> $sampleRow
     */
    private function formatSampleLine(array $sampleRow): string
    {
        $title = (string) ($sampleRow['title'] ?? '(untitled)');
        $url = (string) ($sampleRow['url'] ?? '(no-url)');
        $reason = isset($sampleRow['reason']) ? ' | reason=' . (string) $sampleRow['reason'] : '';
        $quality = isset($sampleRow['quality_score']) ? ' | quality=' . (string) $sampleRow['quality_score'] : '';
        return sprintf('%s | %s%s%s', $title, $url, $quality, $reason);
    }

    private function writeJsonReport(
        string $reportPath,
        NcSyncResult $result,
        int $limit,
        ?string $since,
        bool $dryRun,
        bool $explain,
        int $sample,
    ): void {
        try {
            $payload = [
                'generated_at' => date('c'),
                'options' => [
                    'limit' => $limit,
                    'since' => $since,
                    'dry_run' => $dryRun,
                    'explain' => $explain,
                    'sample' => $sample,
                ],
                'result' => $result->toArray(),
            ];

            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

            $directory = dirname($reportPath);
            if (!is_dir($directory)) {
                @mkdir($directory, 0o775, true);
            }

            $tmp = $reportPath . '.tmp';
            if (file_put_contents($tmp, $json) === false) {
                return;
            }

            rename($tmp, $reportPath);
        } catch (\Throwable) {
            // Report writing is best-effort and should not fail sync execution.
        }
    }
}
