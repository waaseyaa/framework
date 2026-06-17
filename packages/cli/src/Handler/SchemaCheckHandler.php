<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Diagnostic\HealthCheckerInterface;
use Waaseyaa\Foundation\Diagnostic\HealthCheckResult;

/**
 * @api
 */
final class SchemaCheckHandler
{
    public function __construct(
        private readonly HealthCheckerInterface $checker,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $results = $this->checker->checkSchemaDrift();

        if ($io->option('json')) {
            $io->writeln(json_encode(
                array_map(static fn(HealthCheckResult $r) => $r->toArray(), $results),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return $this->hasDrift($results) ? 1 : 0;
        }

        $hasDrift = false;

        foreach ($results as $result) {
            if ($result->status === 'fail') {
                $hasDrift = true;
                $io->writeln(sprintf('DRIFT %s: %s', $result->name, $result->message));

                $drift = $result->context['drift'] ?? [];
                if ($drift !== []) {
                    foreach ($drift as $entry) {
                        $io->writeln(sprintf('  %-30s %s', $entry['column'], $entry['issue']));
                    }
                }

                if ($result->remediation !== '') {
                    $io->writeln(sprintf('  Remediation: %s', $result->remediation));
                }
                $io->writeln('');
            } else {
                $io->writeln(sprintf('OK %s', $result->message));
            }
        }

        return $hasDrift ? 1 : 0;
    }

    /** @param list<HealthCheckResult> $results */
    private function hasDrift(array $results): bool
    {
        foreach ($results as $r) {
            if ($r->status === 'fail') {
                return true;
            }
        }
        return false;
    }
}
