<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Support\AdminPackagePathResolver;

final class AdminBuildHandler
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        try {
            $adminPath = new AdminPackagePathResolver($this->projectRoot)->resolve();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        /** @var array<string, string> $env */
        $env = getenv();
        $io->writeln(sprintf('Admin package: %s', $adminPath));

        $process = proc_open(
            [self::npmBinary(), 'run', 'generate'],
            [STDIN, STDOUT, STDERR],
            $pipes,
            $adminPath,
            $env,
        );

        if (!is_resource($process)) {
            $io->error('Could not start npm.');

            return 1;
        }

        $exitCode = proc_close($process);

        return $exitCode === 0 ? 0 : 1;
    }

    private static function npmBinary(): string
    {
        $npm = getenv('NPM_BINARY');
        if (is_string($npm) && $npm !== '') {
            return $npm;
        }

        return 'npm';
    }
}
