<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\RuntimeState;

/**
 * Deployer-local replica of the two schema-authority fingerprint algorithms
 * {@see \Waaseyaa\Foundation\Migration\LogicalSchemaFingerprint::capture()}
 * and {@see \Waaseyaa\Foundation\Migration\MigrationRepository}'s ledger
 * fingerprint own.
 *
 * `packages/deployer/composer.json` deliberately requires nothing beyond
 * `ext-pdo`, `php` and `deployer/deployer` (#2303, #3127): consumer
 * applications install the deployer recipe into an isolated deploy-tools
 * vendor boundary, separate from the application's own vendor tree. Adding a
 * runtime dependency on `waaseyaa/foundation` here would pull that boundary's
 * entire framework dependency graph (Doctrine DBAL, `waaseyaa/cache`,
 * `waaseyaa/queue`, `waaseyaa/entity`, `waaseyaa/error-handler`,
 * `waaseyaa/routing`, `waaseyaa/access`, `waaseyaa/ai-tools`, and more — see
 * `packages/foundation/composer.json`) into a tool meant to stay small enough
 * to run standalone during a deployment. `SqliteArtifactPreparer` also opens
 * its databases with raw PDO, not the DBAL `Connection` foundation's classes
 * require, so reuse is not a drop-in call either way.
 *
 * `waaseyaa/foundation` is therefore a `require-dev`-only edge here (layer 6
 * -> layer 0, legal per `bin/check-package-layers`), used exclusively by
 * {@see \Waaseyaa\Deployer\Tests\Unit\SchemaAuthorityFingerprintParityTest},
 * which pins this class's output byte-identical to foundation's for the same
 * database content. Any future change to either algorithm must keep both
 * sides in lockstep or that test fails.
 *
 * @internal
 */
final class SchemaAuthorityFingerprint
{
    private const int JSON_FLAGS = \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE
        | \JSON_THROW_ON_ERROR;

    /** Mirrors {@see \Waaseyaa\Foundation\Migration\LogicalSchemaFingerprint::capture()}. */
    public static function logicalSchemaFingerprint(\PDO $pdo): string
    {
        return hash('sha256', self::encode([
            'domain' => 'waaseyaa.logical-sqlite-schema.v1',
            'objects' => self::schemaObjects($pdo),
        ]));
    }

    /**
     * The raw `sqlite_schema` object rows the fingerprint is built from, in
     * the same shape and order `LogicalSchemaFingerprint::capture()` reads
     * them. Exposed so {@see SqliteArtifactPreparer} can diff two databases'
     * schema objects directly, without hashing away which table changed.
     *
     * @return list<array{type:string,name:string,table:string,sql:?string}>
     */
    public static function schemaObjects(\PDO $pdo): array
    {
        $statement = $pdo->query(
            "SELECT type, name, tbl_name, sql
             FROM sqlite_schema
             WHERE name NOT LIKE 'sqlite_%'
             ORDER BY type, name, tbl_name",
        );
        if ($statement === false) {
            throw new \RuntimeException('Could not read sqlite_schema.');
        }

        $objects = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $objects[] = [
                'type' => (string) $row['type'],
                'name' => (string) $row['name'],
                'table' => (string) $row['tbl_name'],
                'sql' => self::normalizeSql($row['sql'] ?? null),
            ];
        }

        return $objects;
    }

    /**
     * Mirrors {@see \Waaseyaa\Foundation\Migration\MigrationRepository::currentLedgerFingerprint()}:
     * an absent ledger fingerprints as an explicit "absent" state rather than
     * an empty row set, and an existing ledger is read in the same column
     * order, keyed the same way, for the same domain.
     */
    public static function ledgerFingerprint(\PDO $pdo): string
    {
        if (!self::tableExists($pdo, 'waaseyaa_migrations')) {
            return hash('sha256', self::encode([
                'domain' => 'waaseyaa.migration-ledger.v1',
                'state' => 'absent',
            ]));
        }

        $statement = $pdo->query(
            'SELECT migration, package, batch, checksum, diff_hash FROM waaseyaa_migrations ORDER BY migration',
        );
        if ($statement === false) {
            throw new \RuntimeException('Could not read the migration ledger.');
        }

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'migration' => (string) $row['migration'],
                'package' => (string) $row['package'],
                'batch' => (int) $row['batch'],
                'checksum' => is_string($row['checksum']) ? $row['checksum'] : null,
                'diff_hash' => is_string($row['diff_hash']) ? $row['diff_hash'] : null,
            ];
        }

        return hash('sha256', self::encode([
            'domain' => 'waaseyaa.migration-ledger.v1',
            'rows' => $rows,
        ]));
    }

    private static function normalizeSql(mixed $sql): ?string
    {
        if (!is_string($sql)) {
            return null;
        }

        return trim(str_replace(["\r\n", "\r"], "\n", $sql));
    }

    private static function tableExists(\PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        if ($statement === false) {
            throw new \RuntimeException('Could not prepare a SQLite statement.');
        }
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Byte-identical rules to
     * {@see \Waaseyaa\Foundation\Schema\Diff\CanonicalJson}: UTF-8 bytes,
     * recursively key-sorted objects (`ksort(SORT_STRING)`), list order
     * preserved, integers stay integers, `null` preserved, no whitespace.
     *
     * @param array<array-key, mixed>|scalar|null $value
     */
    private static function encode(mixed $value): string
    {
        // JSON_THROW_ON_ERROR makes json_encode() throw rather than return
        // false, so its result here is always a string.
        return json_encode(self::canonicalize($value), self::JSON_FLAGS);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        $sorted = $value;
        ksort($sorted, \SORT_STRING);

        return array_map(self::canonicalize(...), $sorted);
    }
}
