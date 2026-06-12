<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\CliIO;
use Waaseyaa\Foundation\Diagnostic\HealthCheckerInterface;
use Waaseyaa\Foundation\Diagnostic\HealthCheckResult;
use Waaseyaa\Foundation\Ingestion\IngestionLogger;
use Waaseyaa\Foundation\Kernel\Bootstrap\DatabaseBootstrapper;
use Waaseyaa\Foundation\Kernel\ConfigLoader;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * @api
 */
final class HealthReportHandler
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HealthCheckerInterface $checker,
        private readonly string $projectRoot,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function execute(CliIO $io): int
    {
        $systemInfo = $this->gatherSystemInfo();
        $healthResults = $this->checker->runAll();
        $ingestionSummary = $this->gatherIngestionSummary();

        $outputFile = $io->option('output');
        if ($outputFile !== null && !$io->option('json')) {
            $io->writeln('The --output option requires --json. Use: health:report --json --output report.json');
            return 1;
        }

        if ($io->option('json')) {
            $report = [
                'generated_at' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
                'system' => $systemInfo,
                'health_checks' => array_map(
                    static fn(HealthCheckResult $r) => $r->toArray(),
                    $healthResults,
                ),
                'ingestion_summary' => $ingestionSummary,
            ];

            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if ($outputFile !== null) {
                file_put_contents((string) $outputFile, $json . "\n");
                $io->writeln(sprintf('Report written to %s', (string) $outputFile));
            } else {
                $io->writeln($json);
            }

            return 0;
        }

        // --- System info ---
        $io->writeln('System Information');
        foreach ($systemInfo as $key => $value) {
            $io->writeln(sprintf('  %-20s %s', $key . ':', $value));
        }
        $io->writeln('');

        // --- Health checks ---
        $io->writeln('Health Checks');
        foreach ($healthResults as $result) {
            $prefix = match ($result->status) {
                'pass' => 'OK  ',
                'warn' => 'WARN',
                'fail' => 'FAIL',
                default => '    ',
            };
            $io->writeln(sprintf('  %s %s: %s', $prefix, $result->name, $result->message));
        }
        $io->writeln('');

        // --- Ingestion summary ---
        if ($ingestionSummary !== []) {
            $io->writeln('Ingestion Summary');
            foreach ($ingestionSummary as $key => $value) {
                $display = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value;
                $io->writeln(sprintf('  %-20s %s', $key . ':', $display));
            }
            $io->writeln('');
        }

        // --- Remediations ---
        $nonPassing = array_filter($healthResults, static fn(HealthCheckResult $r) => $r->status !== 'pass');
        if ($nonPassing !== []) {
            $io->writeln('Remediations:');
            foreach ($nonPassing as $result) {
                if ($result->remediation !== '') {
                    $io->writeln(sprintf('  %s: %s', $result->name, $result->remediation));
                }
            }
        }

        return 0;
    }

    /** @return array<string, string> */
    private function gatherSystemInfo(): array
    {
        return [
            'PHP Version' => PHP_VERSION,
            'OS' => PHP_OS,
            'SAPI' => PHP_SAPI,
            // Resolved through the kernel's canonical resolver (#1650 /
            // FR-007) so operators see the path the kernel actually opens,
            // not the raw env value.
            'Database' => DatabaseBootstrapper::resolveDatabasePath(
                $this->projectRoot,
                ConfigLoader::load($this->projectRoot . '/config/waaseyaa.php'),
            ),
            'Config Dir' => getenv('WAASEYAA_CONFIG_DIR') !== false ? getenv('WAASEYAA_CONFIG_DIR') : './config/sync',
            'Project Root' => $this->projectRoot,
            'Generated At' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function gatherIngestionSummary(): array
    {
        $logger = new IngestionLogger($this->projectRoot);

        try {
            $entries = $logger->read();
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Failed to read ingestion log: %s', $e->getMessage()));
            return [];
        }

        if ($entries === []) {
            return [];
        }

        $accepted = 0;
        $rejected = 0;
        $errorCodes = [];
        $lastAccepted = null;
        $lastRejected = null;

        foreach ($entries as $entry) {
            $entryStatus = $entry['status'] ?? '';
            if ($entryStatus === 'accepted') {
                $accepted++;
                $lastAccepted = $entry['logged_at'] ?? null;
            } elseif ($entryStatus === 'rejected') {
                $rejected++;
                $lastRejected = $entry['logged_at'] ?? null;
                foreach ($entry['errors'] ?? [] as $error) {
                    $code = $error['code'] ?? 'UNKNOWN';
                    $errorCodes[$code] = ($errorCodes[$code] ?? 0) + 1;
                }
            }
        }

        $total = $accepted + $rejected;
        arsort($errorCodes);

        return [
            'total_entries' => $total,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'error_rate' => $total > 0 ? round(($rejected / $total) * 100, 1) . '%' : '0%',
            'last_accepted' => $lastAccepted ?? 'never',
            'last_rejected' => $lastRejected ?? 'never',
            'top_error_codes' => array_slice($errorCodes, 0, 5, true),
        ];
    }
}
