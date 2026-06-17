<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

final class OptimizeHandler
{
    /**
     * Sub-command names to run in order.
     *
     * @var list<string>
     */
    private const array SUB_COMMANDS = [
        'optimize:manifest',
        'optimize:config',
    ];

    /**
     * @param array<string, \Closure(SymfonyCommandIO): int> $subHandlers  Keyed by command name.
     */
    public function __construct(
        private readonly array $subHandlers,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $ranAny = false;

        foreach (self::SUB_COMMANDS as $commandName) {
            if (!isset($this->subHandlers[$commandName])) {
                $io->writeln(sprintf('Skipping %s (not registered).', $commandName));
                continue;
            }

            $io->writeln(sprintf('Running %s...', $commandName));
            $result = ($this->subHandlers[$commandName])($io);

            if ($result !== 0) {
                $io->writeln(sprintf('%s failed.', $commandName));
                return 1;
            }

            $ranAny = true;
        }

        if (!$ranAny) {
            $io->writeln('No optimization commands are registered.');
            return 0;
        }

        $io->writeln('All optimizations complete.');

        return 0;
    }
}
