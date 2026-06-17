<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class TelescopeListHandler
{
    /**
     * @param object|null $store Telescope data store. Null when telescope is not installed.
     */
    public function __construct(
        private readonly ?object $store = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        if ($this->store === null) {
            $io->writeln('Telescope is not enabled. Set telescope.enabled: true in configuration.');

            return 0;
        }

        $type = $io->option('type');
        $limit = (int) ($io->option('limit') ?? '20');
        $slow = $io->option('slow');

        /** @var array<int, array{time?: string, type?: string, summary?: string, duration?: float}> $entries */
        $entries = [];
        if (method_exists($this->store, 'getEntries')) {
            $entries = $this->store->getEntries(
                is_string($type) ? $type : null,
                $limit,
                $slow !== null ? (int) $slow : null,
            );
        }

        if ($entries === []) {
            $io->writeln('No telescope entries found.');

            return 0;
        }

        foreach ($entries as $entry) {
            $duration = isset($entry['duration']) ? $entry['duration'] . 'ms' : '-';
            $io->writeln(sprintf(
                '%s  %-12s  %s  %s',
                $entry['time'] ?? '',
                $entry['type'] ?? '',
                $entry['summary'] ?? '',
                $duration,
            ));
        }

        return 0;
    }
}
