<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Bootstrap;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

final class DatabaseBootstrapper
{
    /**
     * Create and return the database connection.
     *
     * @param array<string, mixed> $config Application config.
     */
    public function boot(string $projectRoot, array $config, ?LoggerInterface $logger = null): DatabaseInterface
    {
        $path = $this->resolvePath($projectRoot, $config, $logger ?? new NullLogger());
        $this->guardMissingProductionSqliteDatabase($path, $config);

        return DBALDatabase::createSqlite($path);
    }

    /**
     * Resolve the configured database path against the project root.
     *
     * Precedence (unchanged): `config['database']` → `WAASEYAA_DB` env →
     * `{projectRoot}/storage/waaseyaa.sqlite`. A relative value from either
     * source absolutizes against the project root, so the resolved path is a
     * pure function of (configured value, projectRoot) — process CWD never
     * participates (#1650 / FR-007). Shared by every kernel runtime via
     * boot() and by the CLI's `db:init` and display handlers.
     */
    public static function resolveDatabasePath(string $projectRoot, array $config): string
    {
        $configured = $config['database'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return self::absolutize($configured, $projectRoot);
        }

        $env = getenv('WAASEYAA_DB');
        if (is_string($env) && $env !== '') {
            return self::absolutize($env, $projectRoot);
        }

        // Unset default: byte-identical to the pre-mission concatenation.
        return $projectRoot . '/storage/waaseyaa.sqlite';
    }

    /**
     * Absolutize a configured database path against the project root.
     *
     * Passed through untouched: `:memory:`, leading `/`, Windows drive-letter
     * (`X:` followed by a separator), and UNC (`\\`) values. Relative values
     * have a single leading `./` stripped and are prefixed with the project
     * root. Climbing `../` values are relatives — they concatenate onto the
     * project root and resolve naturally; they are not collapsed here.
     */
    public static function absolutize(string $path, string $projectRoot): string
    {
        if (self::isAbsoluteOrMemory($path)) {
            return $path;
        }

        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return rtrim($projectRoot, '/\\') . '/' . $path;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolvePath(string $projectRoot, array $config, LoggerInterface $logger): string
    {
        $dbPath = self::resolveDatabasePath($projectRoot, $config);

        $this->warnWhenInsideDocroot($dbPath, $projectRoot, $logger);

        if ($this->isProductionEnvironment($config) && $dbPath !== ':memory:' && !file_exists($dbPath)) {
            return $dbPath;
        }

        // Ensure the parent directory exists so SQLite can create the file.
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0o755, recursive: true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf(
                'Failed to create the database directory "%s" for the SQLite database at "%s". '
                . 'Check that the parent path exists and is writable.',
                $dir,
                $dbPath,
            ));
        }

        return $dbPath;
    }

    /**
     * FR-008: warn (never refuse) when the resolved database path lands
     * inside the public docroot — the SQLite file would be one static-config
     * mistake away from being a downloadable URL. Best-effort advisory:
     * the check is pure string logic and the warning never aborts boot.
     */
    private function warnWhenInsideDocroot(string $resolvedPath, string $projectRoot, LoggerInterface $logger): void
    {
        if ($resolvedPath === ':memory:') {
            return;
        }

        $docroot = $this->normalizeLexically(rtrim($projectRoot, '/\\') . '/public');
        $normalized = $this->normalizeLexically($resolvedPath);

        if ($normalized === $docroot || str_starts_with($normalized, $docroot . '/')) {
            $logger->warning(sprintf(
                'Database path %s resolves inside the public docroot %s — the SQLite file may be '
                . 'directly downloadable. Correct WAASEYAA_DB or config[\'database\'] to point '
                . 'outside the docroot (e.g. storage/waaseyaa.sqlite).',
                $resolvedPath,
                rtrim($projectRoot, '/\\') . '/public',
            ));
        }
    }

    /**
     * Lexically normalize a path: unify separators and resolve `.`/`..`
     * segments. Deliberately NOT realpath() — the database file may not
     * exist yet at first boot, and the containment check must stay a pure
     * string comparison.
     */
    private function normalizeLexically(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        $prefix = '';
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            $prefix = substr($path, 0, 2);
            $path = substr($path, 2);
        } elseif (str_starts_with($path, '//')) {
            // UNC: preserve the leading double slash via the prefix.
            $prefix = '/';
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                } else {
                    $segments[] = '..';
                }
                continue;
            }
            $segments[] = $segment;
        }

        $root = str_starts_with($path, '/') ? '/' : '';

        return $prefix . $root . implode('/', $segments);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function guardMissingProductionSqliteDatabase(string $path, array $config): void
    {
        if (!$this->isProductionEnvironment($config)) {
            return;
        }

        if ($path === ':memory:' || file_exists($path)) {
            return;
        }

        throw new \RuntimeException(
            sprintf(
                'Database not found at %s. In production, the database must already exist. '
                . 'Run "bin/waaseyaa db:init" to create the database file and apply migrations. '
                . 'The command is idempotent and safe to run on every deploy.',
                $path,
            ),
        );
    }

    private static function isAbsoluteOrMemory(string $path): bool
    {
        return $path === ':memory:'
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isProductionEnvironment(array $config): bool
    {
        return strtolower($this->resolveEnvironment($config)) === 'production';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveEnvironment(array $config): string
    {
        $env = $config['environment'] ?? getenv('APP_ENV') ?: 'production';

        return is_string($env) && $env !== '' ? $env : 'production';
    }
}
