<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Backend;

use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\CacheItem;
use Waaseyaa\Cache\TagAwareCacheInterface;

/**
 * Cache backend that stores cache items in a database table via PDO.
 *
 * The table schema:
 *   cid    VARCHAR(255) PRIMARY KEY
 *   data   BLOB
 *   expire INTEGER
 *   created INTEGER
 *   tags   TEXT (comma-separated)
 *   valid  INTEGER (0 or 1)
 */
final class DatabaseBackend implements TagAwareCacheInterface
{
    private bool $tableInitialized = false;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $bin = 'cache_default',
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function get(string $cid): CacheItem|false
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "SELECT cid, data, expire, created, tags, valid FROM {$this->bin} WHERE cid = :cid",
        );
        $stmt->execute([':cid' => $cid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        return $this->mapRowToItem($row);
    }

    /** @return array<string, CacheItem> */
    public function getMultiple(array &$cids): array
    {
        $this->ensureTable();

        if ($cids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT cid, data, expire, created, tags, valid FROM {$this->bin} WHERE cid IN ({$placeholders})",
        );
        $stmt->execute(array_values($cids));
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
        $this->ensureTable();

        $serialized = serialize($data);
        $tagsString = implode(',', $tags);
        $now = time();

        $stmt = $this->pdo->prepare(
            "INSERT OR REPLACE INTO {$this->bin} (cid, data, expire, created, tags, valid) VALUES (:cid, :data, :expire, :created, :tags, :valid)",
        );
        $stmt->execute([
            ':cid' => $cid,
            ':data' => $serialized,
            ':expire' => $expire,
            ':created' => $now,
            ':tags' => $tagsString,
            ':valid' => 1,
        ]);
    }

    public function delete(string $cid): void
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare("DELETE FROM {$this->bin} WHERE cid = :cid");
        $stmt->execute([':cid' => $cid]);
    }

    public function deleteMultiple(array $cids): void
    {
        if ($cids === []) {
            return;
        }

        $this->ensureTable();

        $placeholders = implode(',', array_fill(0, count($cids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM {$this->bin} WHERE cid IN ({$placeholders})");
        $stmt->execute(array_values($cids));
    }

    public function deleteAll(): void
    {
        $this->ensureTable();
        $this->pdo->prepare("DELETE FROM {$this->bin}")->execute();
    }

    public function invalidate(string $cid): void
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare("UPDATE {$this->bin} SET valid = 0 WHERE cid = :cid");
        $stmt->execute([':cid' => $cid]);
    }

    public function invalidateMultiple(array $cids): void
    {
        if ($cids === []) {
            return;
        }

        $this->ensureTable();

        $placeholders = implode(',', array_fill(0, count($cids), '?'));
        $stmt = $this->pdo->prepare("UPDATE {$this->bin} SET valid = 0 WHERE cid IN ({$placeholders})");
        $stmt->execute(array_values($cids));
    }

    public function invalidateAll(): void
    {
        $this->ensureTable();
        $this->pdo->prepare("UPDATE {$this->bin} SET valid = 0")->execute();
    }

    public function removeBin(): void
    {
        $this->pdo->prepare("DROP TABLE IF EXISTS {$this->bin}")->execute();
        $this->tableInitialized = false;
    }

    /** @param string[] $tags */
    public function invalidateByTags(array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $this->ensureTable();

        // Build a WHERE clause that matches any of the specified tags.
        // Tags are stored comma-separated, so we use LIKE patterns.
        $conditions = [];
        $params = [];
        foreach ($tags as $i => $tag) {
            $paramName = ":tag{$i}";
            $paramStart = ":tagstart{$i}";
            $paramEnd = ":tagend{$i}";
            $paramMiddle = ":tagmid{$i}";
            $conditions[] = "(tags = {$paramName} OR tags LIKE {$paramStart} OR tags LIKE {$paramEnd} OR tags LIKE {$paramMiddle})";
            $params[$paramName] = $tag;
            $params[$paramStart] = $tag . ',%';
            $params[$paramEnd] = '%,' . $tag;
            $params[$paramMiddle] = '%,' . $tag . ',%';
        }

        $where = implode(' OR ', $conditions);
        $stmt = $this->pdo->prepare("UPDATE {$this->bin} SET valid = 0 WHERE {$where}");
        $stmt->execute($params);
    }

    private function ensureTable(): void
    {
        if ($this->tableInitialized) {
            return;
        }

        $this->pdo->prepare(
            "CREATE TABLE IF NOT EXISTS {$this->bin} (
                cid VARCHAR(255) NOT NULL PRIMARY KEY,
                data BLOB NOT NULL,
                expire INTEGER NOT NULL DEFAULT -1,
                created INTEGER NOT NULL DEFAULT 0,
                tags TEXT NOT NULL DEFAULT '',
                valid INTEGER NOT NULL DEFAULT 1
            )",
        )->execute();

        $this->tableInitialized = true;
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

        return new CacheItem(
            cid: $row['cid'],
            data: unserialize($row['data']),
            created: $created,
            expire: $expire,
            tags: $tags,
            valid: (bool) $row['valid'],
        );
    }
}
