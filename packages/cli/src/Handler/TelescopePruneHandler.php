<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class TelescopePruneHandler
{
    private const int DEFAULT_HOURS = 24;

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

        $hours = (int) ($io->option('hours') ?? (string) self::DEFAULT_HOURS);

        if (method_exists($this->store, 'prune')) {
            $before = new \DateTimeImmutable("-{$hours} hours");
            $pruned = $this->store->prune($before);
            $io->writeln(sprintf('Pruned %d telescope entries older than %d hours.', $pruned, $hours));
        } else {
            $io->writeln(sprintf('Pruned telescope entries older than %d hours.', $hours));
        }

        return 0;
    }
}
