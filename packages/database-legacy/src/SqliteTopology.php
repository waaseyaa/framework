<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

use Doctrine\DBAL\Connection;

/**
 * Executable S1 SQLite connection and path invariants.
 */
final class SqliteTopology
{
    public const int BUSY_TIMEOUT_MS = 5000;
    public const string INVALID_PATH = 'S1-DB001';
    public const string PRODUCTION_MEMORY = 'S1-DB002';
    public const string PRAGMA_MISMATCH = 'S1-DB003';

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

        if ($path === ':memory:' && strtolower($environment) === 'production') {
            throw new \RuntimeException(sprintf(
                '%s: The S1 production profile requires one file-backed authoritative SQLite database; :memory: is development/test only.',
                self::PRODUCTION_MEMORY,
            ));
        }
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
