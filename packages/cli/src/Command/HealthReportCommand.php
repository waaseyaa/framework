<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Foundation\Diagnostic\HealthCheckerInterface;
use Waaseyaa\Foundation\Diagnostic\HealthCheckResult;
use Waaseyaa\Foundation\Ingestion\IngestionLogger;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

#[AsCommand(
    name: 'health:report',
    description: 'Generate a full diagnostic report for operator review',
)]
final class HealthReportCommand extends Command
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HealthCheckerInterface $checker,
        private readonly string $projectRoot,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write report to file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $systemInfo = $this->gatherSystemInfo();
        $healthResults = $this->checker->runAll();
        $ingestionSummary = $this->gatherIngestionSummary();

        $outputFile = $input->getOption('output');
        if ($outputFile !== null && !$input->getOption('json')) {
            $output->writeln('<error>The --output option requires --json. Use: health:report --json --output report.json</error>');
            return self::FAILURE;
        }

        if ($input->getOption('json')) {
            $report = [
                'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'system' => $systemInfo,
                'health_checks' => array_map(
                    static fn(HealthCheckResult $r) => $r->toArray(),
                    $healthResults,
                ),
                'ingestion_summary' => $ingestionSummary,
            ];

            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if ($outputFile !== null) {
                file_put_contents($outputFile, $json . "\n");
                $output->writeln(sprintf('<info>Report written to %s</info>', $outputFile));
            } else {
                $output->writeln($json);
            }

            return self::SUCCESS;
        }

        // --- System info ---
        $output->writeln('<info>System Information</info>');
        $table = new Table($output);
        $table->setStyle('compact');
        foreach ($systemInfo as $key => $value) {
            $table->addRow([$key, (string) $value]);
        }
        $table->render();
        $output->writeln('');

        // --- Health checks ---
        $output->writeln('<info>Health Checks</info>');
        $table = new Table($output);
        $table->setHeaders(['Status', 'Check', 'Message']);
        foreach ($healthResults as $result) {
            $statusLabel = match ($result->status) {
                'pass' => '<info>PASS</info>',
                'warn' => '<comment>WARN</comment>',
                'fail' => '<error>FAIL</error>',
                default => $result->status,
            };
            $table->addRow([$statusLabel, $result->name, $result->message]);
        }
        $table->render();
        $output->writeln('');

        // --- Ingestion summary ---
        if ($ingestionSummary !== []) {
            $output->writeln('<info>Ingestion Summary</info>');
            $table = new Table($output);
            $table->setStyle('compact');
            foreach ($ingestionSummary as $key => $value) {
                $display = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : (string) $value;
                $table->addRow([$key, $display]);
            }
            $table->render();
            $output->writeln('');
        }

        // --- Remediations ---
        $nonPassing = array_filter($healthResults, static fn(HealthCheckResult $r) => $r->status !== 'pass');
        if ($nonPassing !== []) {
            $output->writeln('<comment>Remediations:</comment>');
            foreach ($nonPassing as $result) {
                if ($result->remediation !== '') {
                    $output->writeln(sprintf('  %s: %s', $result->name, $result->remediation));
                }
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function gatherSystemInfo(): array
    {
        return [
            'PHP Version' => PHP_VERSION,
            'OS' => PHP_OS,
            'SAPI' => PHP_SAPI,
            'Database' => getenv('WAASEYAA_DB') ?: './storage/waaseyaa.sqlite',
            'Config Dir' => getenv('WAASEYAA_CONFIG_DIR') ?: './config/sync',
            'Project Root' => $this->projectRoot,
            'Generated At' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
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
            $status = $entry['status'] ?? '';
            if ($status === 'accepted') {
                $accepted++;
                $lastAccepted = $entry['logged_at'] ?? null;
            } elseif ($status === 'rejected') {
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
