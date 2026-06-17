<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

final class ServeHandler
{
    public function __construct(private readonly string $projectRoot) {}

    /**
     * Build the environment the PHP child server will run under.
     *
     * If the caller hasn't set APP_ENV, default to development and force
     * APP_DEBUG=1 so dev-mode database auto-creation and boot-error
     * visibility kick in. All other parent env vars pass through.
     *
     * @param array<string, string> $parentEnv
     * @return array<string, string>
     */
    public function resolveChildEnv(array $parentEnv): array
    {
        $env = $parentEnv;

        if (!isset($env['APP_ENV']) || $env['APP_ENV'] === '') {
            $env['APP_ENV'] = 'development';
            $env['APP_DEBUG'] = '1';
        }

        return $env;
    }

    public function execute(SymfonyCommandIO $io): int
    {
        $host = $io->option('host') ?? (getenv('APP_HOST') !== false ? getenv('APP_HOST') : '0.0.0.0');
        $port = $io->option('port') ?? (getenv('APP_PORT') !== false ? getenv('APP_PORT') : '8080');

        $publicIndex = $this->projectRoot . '/public/index.php';
        if (!file_exists($publicIndex) || filesize($publicIndex) === 0) {
            $io->error('public/index.php is missing or empty.');
            $io->error('Run: vendor/bin/waaseyaa make:public');

            return 1;
        }

        /** @var array<string, string> $parentEnv */
        $parentEnv = getenv();
        $env = $this->resolveChildEnv($parentEnv);

        if (($env['APP_ENV'] ?? '') === 'development') {
            $io->writeln(
                'Starting in development mode (APP_ENV=development). '
                . 'Use APP_ENV=production vendor/bin/waaseyaa serve to override.',
            );
        }

        $displayHost = $host === '0.0.0.0' ? 'localhost' : (string) $host;
        $io->writeln(sprintf('Waaseyaa development server started: http://%s:%s', $displayHost, $port));
        $io->writeln('Press Ctrl+C to stop.');

        $process = proc_open(
            [PHP_BINARY, '-S', "{$host}:{$port}", '-t', $this->projectRoot . '/public', $publicIndex],
            [STDIN, STDOUT, STDERR],
            $pipes,
            null,
            $env,
        );

        if ($process === false) {
            $io->error('Failed to start the development server.');

            return 1;
        }

        proc_close($process);

        return 0;
    }
}
