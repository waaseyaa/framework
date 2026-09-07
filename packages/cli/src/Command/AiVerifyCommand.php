<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

/** Transport adapter; the supplying package owns verification and report policy. */
final class AiVerifyCommand
{
    /**
     * The callback must return deterministic bounded output without private source
     * bytes. A null root represents failed cwd resolution; the callback owns its
     * diagnostic. Exit status, JSON and human lines describe the same result.
     *
     * @param \Closure(?string, ?array): array{exit_code: int, json: string, lines: list<string>} $verify
     */
    public function __construct(private readonly \Closure $verify) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $root = realpath((string) getcwd());
        $raw = $io->option('client');
        $entries = is_array($raw) ? $raw : (is_string($raw) ? [$raw] : []);
        $clients = [];
        foreach ($entries as $entry) {
            foreach (explode(',', (string) $entry) as $client) {
                $client = strtolower(trim($client));
                if ($client !== '') {
                    $clients[$client] = $client;
                }
            }
        }
        ksort($clients);
        $report = ($this->verify)($root === false ? null : $root, $clients === [] ? null : array_values($clients));
        if ((bool) $io->option('json')) {
            $io->writeln(rtrim($report['json']));
        } else {
            foreach ($report['lines'] as $line) {
                $io->writeln($line);
            }
        }

        return $report['exit_code'];
    }
}
