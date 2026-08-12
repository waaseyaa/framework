<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;

/**
 * Executable S1 SQLite connection and path invariants.
 */
final class SqliteTopology
{
    public const int BUSY_TIMEOUT_MS = 5000;
    public const string INVALID_PATH = 'S1-DB001';
    public const string PRODUCTION_MEMORY = 'S1-DB002';
    public const string PRAGMA_MISMATCH = 'S1-DB003';

    /** @var list<string> */
    public const array MEMORY_ENVIRONMENTS = ['local', 'dev', 'development', 'testing'];

    public static function assertSupportedPath(string $path): void
    {
        if ($path === ':memory:') {
            return;
        }

        $isWindowsDrivePath = preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
        $isSchemeOrDsn = !$isWindowsDrivePath
            && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/D', $path) === 1;
        $isNetworkOrDevicePath = str_starts_with($path, '\\\\')
            || str_starts_with($path, '//')
            || str_starts_with($path, '\\?\\')
            || str_starts_with($path, '\\.\\');

        if ($path === '' || str_contains($path, "\0") || $isSchemeOrDsn || $isNetworkOrDevicePath) {
            throw new \RuntimeException(sprintf(
                '%s: Unsupported S1 SQLite path. Configure a local filesystem path, not a DSN, URI, UNC share, or device path.',
                self::INVALID_PATH,
            ));
        }
    }

    public static function assertEnvironmentAllowsPath(string $path, string $environment): void
    {
        self::assertSupportedPath($path);

        if ($path === ':memory:' && !in_array(strtolower($environment), self::MEMORY_ENVIRONMENTS, true)) {
            throw new \RuntimeException(sprintf(
                '%s: The S1 non-development profile requires file-backed SQLite; :memory: is allowed only for local, dev, development, and testing.',
                self::PRODUCTION_MEMORY,
            ));
        }
    }

    /** @param array<string, mixed> $config */
    public static function resolveEnvironment(array $config): string
    {
        $configured = $config['environment'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $environment = getenv('APP_ENV');
        if (is_string($environment) && $environment !== '') {
            return $environment;
        }

        return 'production';
    }

    public static function configureAndVerify(Connection $connection, bool $fileBacked): void
    {
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $connection->executeStatement('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        if ($fileBacked) {
            $connection->executeStatement('PRAGMA journal_mode = WAL');
        }

        self::assertEffectivePragmas($connection, $fileBacked);
    }

    public static function assertEffectivePragmas(Connection $connection, bool $fileBacked): void
    {
        $actual = [
            'foreign_keys' => (int) $connection->fetchOne('PRAGMA foreign_keys'),
            'busy_timeout' => (int) $connection->fetchOne('PRAGMA busy_timeout'),
            'journal_mode' => strtolower((string) $connection->fetchOne('PRAGMA journal_mode')),
        ];
        self::throwForPragmaMismatches($actual, $fileBacked);
    }

    public static function configureAndVerifyDriverConnection(DriverConnection $connection, bool $fileBacked): void
    {
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        if ($fileBacked) {
            $connection->exec('PRAGMA journal_mode = WAL');
        }

        $actual = [
            'foreign_keys' => (int) $connection->query('PRAGMA foreign_keys')->fetchOne(),
            'busy_timeout' => (int) $connection->query('PRAGMA busy_timeout')->fetchOne(),
            'journal_mode' => strtolower((string) $connection->query('PRAGMA journal_mode')->fetchOne()),
        ];
        self::throwForPragmaMismatches($actual, $fileBacked);
    }

    /** @param array{foreign_keys: int, busy_timeout: int, journal_mode: string} $actual */
    private static function throwForPragmaMismatches(array $actual, bool $fileBacked): void
    {
        $mismatches = [];

        if ($actual['foreign_keys'] !== 1) {
            $mismatches[] = sprintf('foreign_keys=%d (expected 1)', $actual['foreign_keys']);
        }
        if ($actual['busy_timeout'] !== self::BUSY_TIMEOUT_MS) {
            $mismatches[] = sprintf(
                'busy_timeout=%d (expected %d)',
                $actual['busy_timeout'],
                self::BUSY_TIMEOUT_MS,
            );
        }
        if ($fileBacked && $actual['journal_mode'] !== 'wal') {
            $mismatches[] = sprintf('journal_mode=%s (expected wal)', $actual['journal_mode']);
        }

        if ($mismatches !== []) {
            throw new \RuntimeException(sprintf(
                '%s: Effective SQLite connection settings violate the S1 contract: %s.',
                self::PRAGMA_MISMATCH,
                implode(', ', $mismatches),
            ));
        }
    }
}
