<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

final class ServeHandler
{
    public function __construct(private readonly string $projectRoot) {}

    /** Worker count the PHP built-in server runs with unless the caller overrides it. */
    public const string DEFAULT_SERVER_WORKERS = '4';

    /**
     * Build the environment the PHP child server will run under.
     *
     * If the caller hasn't set APP_ENV, default to development and force
     * APP_DEBUG=1 so dev-mode database auto-creation and boot-error
     * visibility kick in. All other parent env vars pass through.
     *
     * PHP's built-in server is single-worker by default, which deadlocks against
     * the admin SPA's long-lived SSE connection (`/api/broadcast`): that one
     * stream pins the sole worker and every other request blocks. So when the
     * caller hasn't set PHP_CLI_SERVER_WORKERS we default it to
     * {@see DEFAULT_SERVER_WORKERS} — no reliance on a composer/shell wrapper.
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

        if (!isset($env['PHP_CLI_SERVER_WORKERS']) || $env['PHP_CLI_SERVER_WORKERS'] === '') {
            $env['PHP_CLI_SERVER_WORKERS'] = self::DEFAULT_SERVER_WORKERS;
        }

        return $env;
    }

    /** Env var overriding the FrankenPHP binary location. */
    public const string FRANKENPHP_BIN_ENV = 'WAASEYAA_FRANKENPHP_BIN';

    /** Env var overriding the FrankenPHP php.ini path. */
    public const string FRANKENPHP_INI_ENV = 'WAASEYAA_FRANKENPHP_INI';

    /** App-relative default php.ini that enables pdo_sqlite/sqlite3 for FrankenPHP. */
    public const string FRANKENPHP_INI_RELATIVE = 'config/frankenphp/php.ini';

    /**
     * Resolve the FrankenPHP binary: {@see FRANKENPHP_BIN_ENV} override, else
     * `frankenphp` on PATH.
     *
     * @param array<string, string> $env
     */
    public function frankenphpBinary(array $env): string
    {
        $bin = $env[self::FRANKENPHP_BIN_ENV] ?? '';

        return $bin !== '' ? $bin : 'frankenphp';
    }

    /**
     * Resolve the php.ini path used under FrankenPHP: {@see FRANKENPHP_INI_ENV}
     * override, else `{projectRoot}/config/frankenphp/php.ini`. This ini enables
     * pdo_sqlite/sqlite3 so a stock SQLite app boots with no hand-editing.
     *
     * @param array<string, string> $env
     */
    public function frankenphpIniPath(array $env): string
    {
        $ini = $env[self::FRANKENPHP_INI_ENV] ?? '';

        return $ini !== '' ? $ini : $this->projectRoot . '/' . self::FRANKENPHP_INI_RELATIVE;
    }

    /**
     * The FrankenPHP launch argv. Classic `php-server` mode — concurrent across
     * threads (which, with the bounded broadcast loop, is what keeps the admin
     * SPA responsive while an SSE stream is open). Worker mode is opt-in via a
     * Caddyfile for advanced setups.
     *
     * @return list<string>
     */
    public function frankenphpCommand(string $binary, string $host, string $port): array
    {
        return [
            $binary,
            'php-server',
            '--listen',
            "{$host}:{$port}",
            '--root',
            $this->projectRoot . '/public',
        ];
    }

    /**
     * Child env for the FrankenPHP process: dev defaults plus `PHPRC` pointed at
     * the php.ini's directory so the embedded PHP loads our SQLite-enabling ini.
     * `PHP_CLI_SERVER_WORKERS` is intentionally NOT set (that is a `php -S`-only
     * knob; FrankenPHP manages its own thread pool).
     *
     * @param array<string, string> $parentEnv
     * @return array<string, string>
     */
    public function resolveFrankenphpEnv(array $parentEnv, string $iniPath): array
    {
        $env = $parentEnv;

        if (!isset($env['APP_ENV']) || $env['APP_ENV'] === '') {
            $env['APP_ENV'] = 'development';
            $env['APP_DEBUG'] = '1';
        }

        if (!isset($env['PHPRC']) || $env['PHPRC'] === '') {
            // PHP reads php.ini from the PHPRC directory.
            $env['PHPRC'] = \dirname($iniPath);
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

        if ((bool) $io->option('frankenphp')) {
            return $this->serveFrankenphp($io, $parentEnv, (string) $host, (string) $port);
        }

        $env = $this->resolveChildEnv($parentEnv);

        if (($env['APP_ENV'] ?? '') === 'development') {
            $io->writeln(
                'Starting in development mode (APP_ENV=development). '
                . 'Use APP_ENV=production vendor/bin/waaseyaa serve to override.',
            );
        }

        $displayHost = $host === '0.0.0.0' ? 'localhost' : (string) $host;
        $io->writeln(sprintf('Waaseyaa development server started: http://%s:%s', $displayHost, $port));
        $io->writeln(sprintf(
            'Concurrency: %s PHP worker(s) (PHP_CLI_SERVER_WORKERS). The admin SPA holds a '
            . 'long-lived SSE connection, so the server needs >1 worker to stay responsive.',
            $env['PHP_CLI_SERVER_WORKERS'] ?? '1',
        ));
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

    /**
     * @param array<string, string> $parentEnv
     */
    private function serveFrankenphp(SymfonyCommandIO $io, array $parentEnv, string $host, string $port): int
    {
        $iniPath = $this->frankenphpIniPath($parentEnv);
        if (!is_file($iniPath)) {
            $io->error(sprintf('FrankenPHP php.ini not found at %s.', $iniPath));
            $io->error(sprintf(
                'Create it (it must enable pdo_sqlite + sqlite3), or set %s to its path.',
                self::FRANKENPHP_INI_ENV,
            ));

            return 1;
        }

        $binary = $this->frankenphpBinary($parentEnv);
        $env = $this->resolveFrankenphpEnv($parentEnv, $iniPath);
        $command = $this->frankenphpCommand($binary, $host, $port);

        $displayHost = $host === '0.0.0.0' ? 'localhost' : $host;
        $io->writeln(sprintf('Waaseyaa development server (FrankenPHP) started: http://%s:%s', $displayHost, $port));
        $io->writeln(sprintf('Using php.ini: %s (pdo_sqlite/sqlite3 enabled for the SQLite default).', $iniPath));
        $io->writeln('FrankenPHP serves requests concurrently across threads; the admin SPA SSE stays responsive.');
        $io->writeln('Press Ctrl+C to stop.');

        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $env);

        if ($process === false) {
            $io->error(sprintf(
                'Failed to start FrankenPHP (%s). Install it (https://frankenphp.dev) or set %s.',
                $binary,
                self::FRANKENPHP_BIN_ENV,
            ));

            return 1;
        }

        $exitCode = proc_close($process);

        // proc_close returns the child's exit status; a non-zero immediately
        // after launch usually means the binary was not found.
        if ($exitCode !== 0) {
            $io->error(sprintf(
                'FrankenPHP exited with status %d. Ensure `%s` is installed and on PATH (or set %s), '
                . 'and that the binary includes pdo_sqlite/sqlite3.',
                $exitCode,
                $binary,
                self::FRANKENPHP_BIN_ENV,
            ));

            return $exitCode;
        }

        return 0;
    }
}
