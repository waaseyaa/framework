<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class IngestDashboardHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $paths = $this->collectInputPaths($io);
        if ($paths === []) {
            $io->error('No ingest artifacts found. Use --input and/or --glob.');
            return 2;
        }

        $runs = [];
        foreach ($paths as $path) {
            $decoded = $this->readArtifact($path);
            if ($decoded === null) {
                $io->error(sprintf('Unable to decode ingest artifact: %s', $path));
                return 1;
            }
            $runs[] = $this->buildRunRow($path, $decoded);
        }
        usort(
            $runs,
            static fn(array $a, array $b): int => strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? '')),
        );

        $payload = $this->buildDashboardPayload($runs);
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

        $outputPath = trim((string) ($io->option('output') ?? ''));
        if ($outputPath !== '') {
            if (!$this->writeFile($outputPath, $encoded, $io)) {
                return 1;
            }
        }

        if ((bool) $io->option('json')) {
            $io->writeln($encoded);
        } else {
            $io->writeln($this->renderTextDashboard($payload));
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    private function collectInputPaths(SymfonyCommandIO $io): array
    {
        $paths = [];
        $option = $io->option('input');
        if (is_array($option)) {
            foreach ($option as $value) {
                if (!is_string($value)) {
                    continue;
                }
                foreach (explode(',', $value) as $piece) {
                    $path = trim($piece);
                    if ($path !== '') {
                        $paths[] = $path;
                    }
                }
            }
        }

        $globPattern = trim((string) ($io->option('glob') ?? ''));
        if ($globPattern !== '') {
            $matches = glob($globPattern) ?: [];
            sort($matches);
            foreach ($matches as $match) {
                if (is_string($match) && $match !== '') {
                    $paths[] = $match;
                }
            }
        }

        $paths = array_values(array_unique(array_filter(
            $paths,
            static fn(string $path): bool => is_file($path) && is_readable($path),
        )));
        sort($paths);

        return $paths;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readArtifact(string $path): ?array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $artifact
     * @return array<string, mixed>
     */
    private function buildRunRow(string $path, array $artifact): array
    {
        $meta = is_array($artifact['meta'] ?? null) ? $artifact['meta'] : [];
        $nodes = is_array($artifact['nodes'] ?? null) ? $artifact['nodes'] : [];
        $diagnostics = is_array($artifact['diagnostics'] ?? null) ? $artifact['diagnostics'] : [];

        $workflowStateCounts = [];
        $workflowMismatchCount = 0;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $state = strtolower(trim((string) ($node['workflow_state'] ?? 'draft')));
            $workflowStateCounts[$state] = (int) ($workflowStateCounts[$state] ?? 0) + 1;

            $nodeStatus = $this->normalizeStatus($node['status'] ?? 0);
            $expected = $state === 'published' ? 1 : 0;
            if ($nodeStatus !== $expected) {
                $workflowMismatchCount++;
            }
        }
        ksort($workflowStateCounts);

        $schemaCount = count((array) ($diagnostics['schema'] ?? []));
        $validationCount = count((array) ($diagnostics['validation'] ?? []));
        $runtimeCount = count((array) ($diagnostics['errors'] ?? []));
        $inferenceCount = count((array) ($diagnostics['inference'] ?? []));
        $refreshSummary = is_array($diagnostics['refresh_summary'] ?? null) ? $diagnostics['refresh_summary'] : [];
        $refreshRequired = (bool) ($refreshSummary['needs_refresh'] ?? false);
        $refreshPrimary = $refreshSummary['primary_category'] ?? null;

        $errorCount = (int) ($meta['error_count'] ?? ($schemaCount + $validationCount + $runtimeCount));
        $inferredRelationships = (int) ($meta['inferred_relationship_count'] ?? $inferenceCount);
        $policy = (string) ($meta['policy'] ?? '');

        return [
            'path' => $path,
            'batch_id' => (string) ($meta['batch_id'] ?? ''),
            'policy' => $policy,
            'source' => (string) ($meta['source'] ?? ''),
            'node_count' => (int) ($meta['node_count'] ?? count($nodes)),
            'relationship_count' => (int) ($meta['relationship_count'] ?? count((array) ($artifact['relationships'] ?? []))),
            'error_count' => $errorCount,
            'schema_error_count' => $schemaCount,
            'validation_error_count' => $validationCount,
            'runtime_error_count' => $runtimeCount,
            'inferred_relationship_count' => $inferredRelationships,
            'inference_review_pending' => $inferredRelationships,
            'refresh_required' => $refreshRequired,
            'refresh_primary_category' => is_string($refreshPrimary) ? $refreshPrimary : null,
            'workflow_state_counts' => $workflowStateCounts,
            'workflow_mismatch_count' => $workflowMismatchCount,
            'queue_status' => $this->queueStatus($errorCount, $inferredRelationships, $policy),
            'diagnostic_codes' => $this->collectDiagnosticCodes($diagnostics),
        ];
    }

    private function normalizeStatus(mixed $nodeStatus): int
    {
        if (is_bool($nodeStatus)) {
            return $nodeStatus ? 1 : 0;
        }
        if (is_numeric($nodeStatus)) {
            return ((int) $nodeStatus) === 1 ? 1 : 0;
        }
        if (is_string($nodeStatus)) {
            return in_array(strtolower(trim($nodeStatus)), ['1', 'true', 'published', 'yes'], true) ? 1 : 0;
        }

        return 0;
    }

    private function queueStatus(int $errorCount, int $inferredRelationships, string $policy): string
    {
        if ($errorCount > 0) {
            return 'blocked';
        }
        if ($inferredRelationships > 0 || $policy === 'validate_only') {
            return 'review';
        }

        return 'ready';
    }

    /**
     * @param array<string, mixed> $diagnostics
     * @return list<string>
     */
    private function collectDiagnosticCodes(array $diagnostics): array
    {
        $codes = [];
        foreach (['schema', 'validation', 'inference', 'refresh'] as $group) {
            $rows = is_array($diagnostics[$group] ?? null) ? $diagnostics[$group] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string) ($row['code'] ?? ''));
                if ($code !== '') {
                    $codes[$code] = true;
                }
            }
        }

        $diagnosticResult = array_keys($codes);
        sort($diagnosticResult);
        return $diagnosticResult;
    }

    /**
     * @param list<array<string, mixed>> $runs
     * @return array<string, mixed>
     */
    private function buildDashboardPayload(array $runs): array
    {
        $queueStatusCounts = ['blocked' => 0, 'review' => 0, 'ready' => 0];
        $workflowTotals = [];
        $diagnosticCodeCounts = [];
        $refreshCategoryCounts = [];
        $workflowMismatchTotal = 0;
        $inferenceReviewPendingTotal = 0;

        foreach ($runs as $run) {
            $runStatus = (string) ($run['queue_status'] ?? 'ready');
            if (array_key_exists($runStatus, $queueStatusCounts)) {
                $queueStatusCounts[$runStatus]++;
            }

            $workflowCounts = (array) ($run['workflow_state_counts'] ?? []);
            foreach ($workflowCounts as $state => $count) {
                $workflowTotals[(string) $state] = (int) ($workflowTotals[(string) $state] ?? 0) + (int) $count;
            }

            $workflowMismatchTotal += (int) ($run['workflow_mismatch_count'] ?? 0);
            $inferenceReviewPendingTotal += (int) ($run['inference_review_pending'] ?? 0);

            $refreshCategory = $run['refresh_primary_category'] ?? null;
            if (is_string($refreshCategory) && $refreshCategory !== '') {
                $refreshCategoryCounts[$refreshCategory] = (int) ($refreshCategoryCounts[$refreshCategory] ?? 0) + 1;
            }

            foreach ((array) ($run['diagnostic_codes'] ?? []) as $code) {
                $diagnosticCodeCounts[(string) $code] = (int) ($diagnosticCodeCounts[(string) $code] ?? 0) + 1;
            }
        }

        ksort($workflowTotals);
        ksort($diagnosticCodeCounts);
        ksort($refreshCategoryCounts);

        $failed = count(array_filter(
            $runs,
            static fn(array $run): bool => (int) ($run['error_count'] ?? 0) > 0,
        ));
        $refreshRequired = count(array_filter(
            $runs,
            static fn(array $run): bool => (bool) ($run['refresh_required'] ?? false),
        ));

        return [
            'meta' => [
                'surface' => 'ingest_editorial_dashboard',
                'contract_version' => 'v1.5',
                'run_count' => count($runs),
            ],
            'summary' => [
                'queue_status_counts' => $queueStatusCounts,
                'failed_run_count' => $failed,
                'successful_run_count' => count($runs) - $failed,
                'refresh_required_count' => $refreshRequired,
                'inference_review_pending_total' => $inferenceReviewPendingTotal,
                'workflow_mismatch_total' => $workflowMismatchTotal,
                'workflow_state_totals' => $workflowTotals,
                'refresh_category_counts' => $refreshCategoryCounts,
                'diagnostic_code_counts' => $diagnosticCodeCounts,
            ],
            'runs' => $runs,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderTextDashboard(array $payload): string
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $queue = is_array($summary['queue_status_counts'] ?? null) ? $summary['queue_status_counts'] : [];
        $workflowTotals = is_array($summary['workflow_state_totals'] ?? null) ? $summary['workflow_state_totals'] : [];
        $refreshCategoryCounts = is_array($summary['refresh_category_counts'] ?? null) ? $summary['refresh_category_counts'] : [];
        $lines = [];

        $lines[] = 'INGEST EDITORIAL DASHBOARD';
        $lines[] = sprintf('Runs: %d', (int) ($payload['meta']['run_count'] ?? 0));
        $lines[] = sprintf(
            'Queue: blocked=%d review=%d ready=%d',
            (int) ($queue['blocked'] ?? 0),
            (int) ($queue['review'] ?? 0),
            (int) ($queue['ready'] ?? 0),
        );
        $lines[] = sprintf(
            'Failures=%d RefreshRequired=%d InferenceReviewPending=%d WorkflowMismatches=%d',
            (int) ($summary['failed_run_count'] ?? 0),
            (int) ($summary['refresh_required_count'] ?? 0),
            (int) ($summary['inference_review_pending_total'] ?? 0),
            (int) ($summary['workflow_mismatch_total'] ?? 0),
        );

        if ($workflowTotals !== []) {
            $chunks = [];
            foreach ($workflowTotals as $state => $count) {
                $chunks[] = sprintf('%s=%d', (string) $state, (int) $count);
            }
            $lines[] = 'WorkflowTotals: ' . implode(' ', $chunks);
        }

        if ($refreshCategoryCounts !== []) {
            $chunks = [];
            foreach ($refreshCategoryCounts as $category => $count) {
                $chunks[] = sprintf('%s=%d', (string) $category, (int) $count);
            }
            $lines[] = 'RefreshCategories: ' . implode(' ', $chunks);
        }

        $lines[] = 'RUNS';
        foreach ((array) ($payload['runs'] ?? []) as $run) {
            if (!is_array($run)) {
                continue;
            }
            $lines[] = sprintf(
                '- [%s] %s batch=%s errors=%d schema=%d validation=%d inferred=%d refresh=%s',
                (string) ($run['queue_status'] ?? 'ready'),
                (string) ($run['path'] ?? ''),
                (string) ($run['batch_id'] ?? ''),
                (int) ($run['error_count'] ?? 0),
                (int) ($run['schema_error_count'] ?? 0),
                (int) ($run['validation_error_count'] ?? 0),
                (int) ($run['inferred_relationship_count'] ?? 0),
                (bool) ($run['refresh_required'] ?? false) ? (string) ($run['refresh_primary_category'] ?? 'yes') : 'no',
            );
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function writeFile(string $path, string $contents, SymfonyCommandIO $io): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $io->error(sprintf('Unable to create directory: %s', $dir));
            return false;
        }

        if (file_put_contents($path, $contents) === false) {
            $io->error(sprintf('Unable to write file: %s', $path));
            return false;
        }

        $io->writeln(sprintf('Dashboard output written: %s', $path));
        return true;
    }
}
