<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Support\AdminPackagePathResolver;

final class AdminDevHandler
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

        $host = getenv('APP_HOST') !== false && getenv('APP_HOST') !== ''
            ? (string) getenv('APP_HOST')
            : '127.0.0.1';
        $port = getenv('APP_PORT') !== false && getenv('APP_PORT') !== ''
            ? (string) getenv('APP_PORT')
            : '8080';
        $backendUrl = getenv('NUXT_BACKEND_URL') !== false && getenv('NUXT_BACKEND_URL') !== ''
            ? (string) getenv('NUXT_BACKEND_URL')
            : 'http://' . $host . ':' . $port;

        $io->writeln(sprintf('Admin package: %s', $adminPath));
        $io->writeln(sprintf('NUXT_BACKEND_URL=%s', $backendUrl));
        $io->writeln('Press Ctrl+C to stop.');

        /** @var array<string, string> $env */
        $env = getenv();
        $env['NUXT_BACKEND_URL'] = $backendUrl;

        $process = proc_open(
            [self::npmBinary(), 'run', 'dev'],
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
