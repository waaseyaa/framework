<?php

declare(strict_types=1);

namespace Aurora\CLI\Command\Telescope;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists recorded telescope entries (queries, events, requests, etc.).
 */
#[AsCommand(
    name: 'telescope',
    description: 'List recent telescope entries',
)]
final class TelescopeListCommand extends Command
{
    /**
     * @param TelescopeStoreInterface|null $store Telescope data store. Null when telescope is not installed.
     */
    public function __construct(
        private readonly ?object $store = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter by entry type (query, event, request, cache, job, exception)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum entries to show', '20')
            ->addOption('slow', null, InputOption::VALUE_REQUIRED, 'Show only slow queries exceeding threshold in ms');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->store === null) {
            $output->writeln('<comment>Telescope is not enabled. Set telescope.enabled: true in configuration.</comment>');

            return self::SUCCESS;
        }

        $type = $input->getOption('type');
        $limit = (int) $input->getOption('limit');
        $slowThreshold = $input->getOption('slow');

        $entries = $this->fetchEntries($type, $limit, $slowThreshold !== null ? (int) $slowThreshold : null);

        if ($entries === []) {
            $output->writeln('No telescope entries found.');

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Time', 'Type', 'Summary', 'Duration']);

        foreach ($entries as $entry) {
            $table->addRow([
                $entry['time'] ?? '',
                $entry['type'] ?? '',
                $entry['summary'] ?? '',
                isset($entry['duration']) ? $entry['duration'] . 'ms' : '-',
            ]);
        }

        $table->render();

        return self::SUCCESS;
    }

    /**
     * Fetch entries from the telescope store.
     *
     * @return array<int, array{time: string, type: string, summary: string, duration?: float}>
     */
    private function fetchEntries(?string $type, int $limit, ?int $slowThreshold): array
    {
        // The store implements a getEntries method.
        if (method_exists($this->store, 'getEntries')) {
            return $this->store->getEntries($type, $limit, $slowThreshold);
        }

        return [];
    }
}
