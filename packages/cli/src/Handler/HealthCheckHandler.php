<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Diagnostic\HealthCheckerInterface;
use Waaseyaa\Foundation\Diagnostic\HealthCheckResult;

/**
 * @api
 */
final class HealthCheckHandler
{
    public function __construct(
        private readonly HealthCheckerInterface $checker,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $results = $this->checker->runAll();

        if ($io->option('json')) {
            $io->writeln(json_encode(
                array_map(static fn(HealthCheckResult $r) => $r->toArray(), $results),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return $this->worstExitCode($results);
        }

        $hasWarn = false;
        $hasFail = false;

        foreach ($results as $result) {
            if ($result->status === 'fail') {
                $hasFail = true;
                $io->writeln(sprintf('FAIL %s: %s', $result->name, $result->message));
                if ($result->remediation !== '') {
                    $io->writeln(sprintf('  Remediation: %s', $result->remediation));
                }
            } elseif ($result->status === 'warn') {
                $hasWarn = true;
                $io->writeln(sprintf('WARN %s: %s', $result->name, $result->message));
            } else {
                $io->writeln(sprintf('OK   %s: %s', $result->name, $result->message));
            }
        }

        if ($hasFail) {
            $io->writeln('');
            $io->writeln('Health check failed.');
            return 2;
        }

        if ($hasWarn) {
            $io->writeln('');
            $io->writeln('All health checks passed with warnings.');
            return 1;
        }

        $io->writeln('');
        $io->writeln('All health checks passed.');
        return 0;
    }

    /** @param list<HealthCheckResult> $results */
    private function worstExitCode(array $results): int
    {
        $code = 0;
        foreach ($results as $r) {
            if ($r->status === 'fail') {
                return 2;
            }
            if ($r->status === 'warn') {
                $code = 1;
            }
        }
        return $code;
    }
}
