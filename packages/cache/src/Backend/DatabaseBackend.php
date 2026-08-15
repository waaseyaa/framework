<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Backend;

use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\CacheItem;
use Waaseyaa\Cache\EntityPayloadBoundaryConfig;
use Waaseyaa\Cache\ProjectionDeprecationDiagnostic;
use Waaseyaa\Cache\TagAwareCacheInterface;
use Waaseyaa\Foundation\Security\SensitiveKey;

/**
 * Cache backend that stores cache items in a database table via PDO.
 *
 * The table schema:
 *   bin    VARCHAR(128) COMPOSITE PRIMARY KEY
 *   cid    VARCHAR(255) COMPOSITE PRIMARY KEY
 *   data   BLOB
 *   expire INTEGER
 *   created INTEGER
 *   tags   TEXT (comma-separated)
 *   valid  INTEGER (0 or 1)
 *   generation INTEGER (the migration-owned logical invalidation epoch)
 * @api
 */
final class DatabaseBackend implements TagAwareCacheInterface
{
    private const string TABLE = 'cache_items';

    /**
     * Canonical serialized form of boolean false.
     * Used to distinguish a legitimately-cached `false` from an unserialize failure.
     */
    private const string SERIALIZED_FALSE = 'b:0;';

    private bool $tableInitialized = false;
    private readonly ?SensitiveKey $hmacKey;
    private readonly ProjectionDeprecationDiagnostic $projectionDiagnostic;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $bin = 'cache_default',
        #[\SensitiveParameter]
        ?string $hmacKey = null,
        ?ProjectionDeprecationDiagnostic $projectionDiagnostic = null,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->hmacKey = ($hmacKey === '' || $hmacKey === null ? null : new SensitiveKey($hmacKey));
        $this->projectionDiagnostic = $projectionDiagnostic ?? ProjectionDeprecationDiagnostic::forEntityPayloads(
            static function (): void {},
            EntityPayloadBoundaryConfig::enforced(),
        );
    }

    public function get(string $cid): CacheItem|false
    {
        $generation = $this->currentGeneration();

        $stmt = $this->prepare(
            'SELECT cid, data, expire, created, tags, valid FROM ' . self::TABLE
            . ' WHERE bin = :bin AND cid = :cid AND generation = :generation',
        );
        $stmt->execute([':bin' => $this->bin, ':cid' => $cid, ':generation' => $generation]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        return $this->mapRowToItem($row);
    }

    /** @return array<string, CacheItem> */
    public function getMultiple(array &$cids): array
    {
        $generation = $this->currentGeneration();

        if ($cids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cids), '?'));
        $stmt = $this->prepare(
            'SELECT cid, data, expire, created, tags, valid FROM ' . self::TABLE
            . " WHERE bin = ? AND generation = ? AND cid IN ({$placeholders})",
        );
        $stmt->execute([$this->bin, $generation, ...array_values($cids)]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $item = $this->mapRowToItem($row);
            if ($item !== false) {
                $items[$item->cid] = $item;
            }
        }

        $cids = array_values(array_diff($cids, array_keys($items)));

        return $items;
    }

    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void
    {
        $generation = $this->currentGeneration();

        $data = $this->projectionDiagnostic->inspect($cid, $data);
        $serialized = serialize($data);
        $tagsString = implode(',', $tags);
        $now = time();

        $stmt = $this->prepare(
            'INSERT OR REPLACE INTO ' . self::TABLE
            . ' (bin, cid, data, expire, created, tags, valid, generation)'
            . ' VALUES (:bin, :cid, :data, :expire, :created, :tags, :valid, :generation)',
        );
        $stmt->execute([
            ':bin' => $this->bin,
            ':cid' => $cid,
            ':data' => $this->encodePayload($serialized),
            ':expire' => $expire,
            ':created' => $now,
            ':tags' => $tagsString,
            ':valid' => 1,
            ':generation' => $generation,
        ]);
    }

    public function delete(string $cid): void
    {
        $generation = $this->currentGeneration();

        $stmt = $this->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE bin = :bin AND cid = :cid AND generation = :generation',
        );
        $stmt->execute([':bin' => $this->bin, ':cid' => $cid, ':generation' => $generation]);
    }

    public function deleteMultiple(array $cids): void
    {
        if ($cids === []) {
            return;
        }

        $generation = $this->currentGeneration();

        $placeholders = implode(',', array_fill(0, count($cids), '?'));
        $stmt = $this->prepare(
            'DELETE FROM ' . self::TABLE . " WHERE bin = ? AND generation = ? AND cid IN ({$placeholders})",
        );
        $stmt->execute([$this->bin, $generation, ...array_values($cids)]);
    }

    public function deleteAll(): void
    {
        $generation = $this->currentGeneration();
        $this->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE bin = :bin AND generation = :generation',
        )->execute([':bin' => $this->bin, ':generation' => $generation]);
    }

    public function invalidate(string $cid): void
    {
        $generation = $this->currentGeneration();

        $stmt = $this->prepare(
            'UPDATE ' . self::TABLE
            . ' SET valid = 0 WHERE bin = :bin AND cid = :cid AND generation = :generation',
        );
        $stmt->execute([':bin' => $this->bin, ':cid' => $cid, ':generation' => $generation]);
    }

    public function invalidateMultiple(array $cids): void
    {
        if ($cids === []) {
            return;
        }

        $generation = $this->currentGeneration();

        $placeholders = implode(',', array_fill(0, count($cids), '?'));
        $stmt = $this->prepare(
            'UPDATE ' . self::TABLE . " SET valid = 0 WHERE bin = ? AND generation = ? AND cid IN ({$placeholders})",
        );
        $stmt->execute([$this->bin, $generation, ...array_values($cids)]);
    }

    public function invalidateAll(): void
    {
        $generation = $this->currentGeneration();
        $this->prepare(
            'UPDATE ' . self::TABLE . ' SET valid = 0 WHERE bin = :bin AND generation = :generation',
        )->execute([':bin' => $this->bin, ':generation' => $generation]);
    }

    public function removeBin(): void
    {
        $this->ensureTable();
        $this->prepare('DELETE FROM ' . self::TABLE . ' WHERE bin = :bin')->execute([':bin' => $this->bin]);
    }

    /** @param string[] $tags */
    public function invalidateByTags(array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $generation = $this->currentGeneration();

        // Build a WHERE clause that matches any of the specified tags.
        // Tags are stored comma-separated, so we use LIKE patterns to match
        // a tag when it is the only value, first, last, or in the middle of
        // the comma blob. Matching is exact: LIKE metacharacters (% and _) in
        // tag names are escaped with a backslash (the backslash itself is
        // escaped first so a literal backslash in a tag name cannot break the
        // escape sequence), and each LIKE arm carries an explicit ESCAPE '\'
        // clause. SQLite's default LIKE has no escape character, so the clause
        // is required for backslash-escaping to work.
        $conditions = [];
        $params = [];
        foreach ($tags as $i => $tag) {
            $paramName = ":tag{$i}";
            $paramStart = ":tagstart{$i}";
            $paramEnd = ":tagend{$i}";
            $paramMiddle = ":tagmid{$i}";
            // Escape backslash first so a literal \ in $tag becomes \\,
            // then escape % and _ so they are treated as literals, not wildcards.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $tag);
            $conditions[] = "(tags = {$paramName} OR tags LIKE {$paramStart} ESCAPE '\\' OR tags LIKE {$paramEnd} ESCAPE '\\' OR tags LIKE {$paramMiddle} ESCAPE '\\')";
            $params[$paramName] = $tag;
            $params[$paramStart] = $escaped . ',%';
            $params[$paramEnd] = '%,' . $escaped;
            $params[$paramMiddle] = '%,' . $escaped . ',%';
        }

        $where = implode(' OR ', $conditions);
        $stmt = $this->prepare(
            'UPDATE ' . self::TABLE
            . " SET valid = 0 WHERE bin = :bin AND generation = :generation AND ({$where})",
        );
        $params[':bin'] = $this->bin;
        $params[':generation'] = $generation;
        $stmt->execute($params);
    }

    private function ensureTable(): void
    {
        if ($this->tableInitialized) {
            return;
        }

        try {
            $statement = $this->pdo->query("PRAGMA table_info('" . self::TABLE . "')");
            $columns = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN, 1);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                '[S1-DB106] Required runtime cache schema inspection failed. Apply migration "waaseyaa/cache:2026_08_12_000001_cache_items_schema" through the schema coordinator.',
                previous: $exception,
            );
        }

        $missing = array_values(array_diff(
            ['bin', 'cid', 'data', 'expire', 'created', 'tags', 'valid', 'generation'],
            $columns,
        ));
        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                '[S1-DB106] Required runtime schema is unavailable for table "%s"; missing: %s. Apply migration "waaseyaa/cache:2026_08_12_000001_cache_items_schema" through the schema coordinator.',
                self::TABLE,
                implode(', ', $missing),
            ));
        }

        $this->tableInitialized = true;
    }

    private function currentGeneration(): int
    {
        $this->ensureTable();
        try {
            $statement = $this->pdo->query(
                'SELECT generation FROM cache_generation WHERE singleton_id = 1',
            );
            $value = $statement === false ? false : $statement->fetchColumn();
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                '[S1-DB106] Required runtime cache generation is unavailable. Apply migration "waaseyaa/cache:2026_08_15_000002_cache_generation" through the schema coordinator.',
                previous: $exception,
            );
        }
        if ((!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1))
            || (int) $value < 1) {
            throw new \RuntimeException(
                '[S1-DB106] Required runtime cache generation is malformed. Apply migration "waaseyaa/cache:2026_08_15_000002_cache_generation" through the schema coordinator.',
            );
        }

        return (int) $value;
    }

    private function prepare(string $sql): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new \RuntimeException('Cache database could not prepare the required statement.');
        }

        return $statement;
    }

    /**
     * Encode a serialized payload for storage.
     *
     * When an HMAC key is configured, the stored value is a 64-character
     * lowercase hex MAC (sha256) followed immediately by the serialized bytes.
     * Without a key, the serialized string is stored unchanged.
     */
    private function encodePayload(string $serialized): string
    {
        if ($this->hmacKey === null) {
            return $serialized;
        }

        return hash_hmac('sha256', $serialized, $this->hmacKey->bytes()) . $serialized;
    }

    /**
     * Decode a stored payload and verify its integrity.
     *
     * When an HMAC key is configured, the first 64 bytes are the MAC; the
     * remainder is the serialized content. A missing or invalid MAC — including
     * legacy unsigned rows written before the key was configured — returns
     * `false`, which the caller treats as a cache miss (self-heals on next set).
     * Without a key, the stored string is returned unchanged.
     *
     * @return string|false Serialized payload on success; false on verification failure.
     */
    private function decodePayload(string $stored): string|false
    {
        if ($this->hmacKey === null) {
            return $stored;
        }

        if (strlen($stored) < 64) {
            return false;
        }

        $mac = substr($stored, 0, 64);
        $serialized = substr($stored, 64);

        if (!hash_equals(hash_hmac('sha256', $serialized, $this->hmacKey->bytes()), $mac)) {
            return false;
        }

        return $serialized;
    }

    /** @return array{bin: string, table_initialized: bool, hmac_key: string|null} */
    public function __debugInfo(): array
    {
        return [
            'bin' => $this->bin,
            'table_initialized' => $this->tableInitialized,
            'hmac_key' => $this->hmacKey === null ? null : '[REDACTED]',
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Cache backends cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToItem(array $row): CacheItem|false
    {
        $expire = (int) $row['expire'];
        $created = (int) $row['created'];

        // Check expiration.
        if ($expire !== CacheBackendInterface::PERMANENT && $expire < time()) {
            // Remove expired items.
            $this->delete($row['cid']);
            return false;
        }

        $tags = $row['tags'] !== '' ? explode(',', $row['tags']) : [];

        // Trust boundary (D-12): `data` is this application's own serialized cache
        // payload from a server-controlled table; cache values are `mixed` and
        // routinely hold objects, so `allowed_classes => false` would corrupt them.
        // (a) Corrupt or malformed payloads are now treated as a cache miss (never
        //     fatal) — a try/catch around unserialize() plus a SERIALIZED_FALSE guard
        //     ensures a decode failure surfaces as false rather than as an exception
        //     or junk object.
        // (b) When a cache HMAC key is configured the stored payload is integrity-
        //     verified by decodePayload() before unserialize() is called; a bad or
        //     missing signature (including legacy unsigned rows) is treated as a miss
        //     and self-heals on the next set().
        // Kernel-wired bins always receive the cache-specific key derived from
        // WAASEYAA_APP_SECRET; direct package construction may omit it for
        // intentionally volatile/in-memory use.
        // Every read is additionally restricted to the one migration-owned
        // active generation, so a CFG-04 generation CAS invalidates old payloads
        // without parsing, rewriting, or reactivating them during rollback.
        // See docs/specs/infrastructure.md "Stored-payload unserialize() trust boundary (D-12)".
        $serialized = $this->decodePayload((string) $row['data']);
        if ($serialized === false) {
            return false;
        }

        try {
            $value = @unserialize($serialized);
        } catch (\Throwable) {
            return false;
        }

        if ($value === false && $serialized !== self::SERIALIZED_FALSE) {
            return false;
        }

        return new CacheItem(
            cid: $row['cid'],
            data: $value,
            created: $created,
            expire: $expire,
            tags: $tags,
            valid: (bool) $row['valid'],
        );
    }
}
