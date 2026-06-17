<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class TelescopeClearHandler
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

        if (is_string($type)) {
            if (method_exists($this->store, 'clearByType')) {
                $this->store->clearByType($type);
            }
            $io->writeln(sprintf('Telescope entries of type "%s" cleared.', $type));
        } else {
            if (method_exists($this->store, 'clear')) {
                $this->store->clear();
            }
            $io->writeln('All telescope entries cleared.');
        }

        return 0;
    }
}
