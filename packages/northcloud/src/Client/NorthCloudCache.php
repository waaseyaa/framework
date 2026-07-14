<?php

declare(strict_types=1);

namespace Waaseyaa\NorthCloud\Client;

use PDO;

/**
 * SQLite-backed response cache for North Cloud API calls.
 *
 * Keyed by URL. Cache table is created lazily on first use.
 */
final class NorthCloudCache
{
    private bool $tableEnsured = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $ttl = 3600,
    ) {}

    public function get(string $key): ?string
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            'SELECT response_body FROM nc_api_cache WHERE cache_key = :key AND expires_at > :now',
        );
        if ($stmt === false) {
            throw new \RuntimeException('NorthCloudCache: failed to prepare SELECT');
        }
        $stmt->execute(['key' => $this->canonicalize($key), 'now' => time()]);
        $result = $stmt->fetchColumn();

        return $result !== false ? (string) $result : null;
    }

    public function set(string $key, string $value): void
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO nc_api_cache (cache_key, response_body, expires_at) VALUES (:key, :body, :expires)',
        );
        if ($stmt === false) {
            throw new \RuntimeException('NorthCloudCache: failed to prepare INSERT');
        }
        $stmt->execute([
            'key' => $this->canonicalize($key),
            'body' => $value,
            'expires' => time() + $this->ttl,
        ]);
    }

    /**
     * Canonicalize a URL so semantically-equivalent URLs hash to the same cache key.
     *
     * - Scheme and host are lowercased.
     * - Query parameters are sorted alphabetically.
     * - URL fragments are dropped.
     *
     * Non-URL inputs (no scheme) are returned as-is.
     */
    private function canonicalize(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user = isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '';
        $path = $parts['path'] ?? '';

        $queryString = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $params);
            $params = $this->sortRecursive($params);
            $queryString = '?' . http_build_query($params);
        }

        return $scheme . '://' . $user . $host . $port . $path . $queryString;
    }

    /**
     * Sort nested query params so semantically-equal array filters normalize.
     *
     * @param array<string|int, mixed> $value
     * @return array<string|int, mixed>
     */
    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        if (array_is_list($value)) {
            sort($value);
            return $value;
        }

        ksort($value);
        return $value;
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS nc_api_cache ('
            . 'cache_key TEXT PRIMARY KEY, '
            . 'response_body TEXT NOT NULL, '
            . 'expires_at INTEGER NOT NULL'
            . ')',
        );
        $this->tableEnsured = true;
    }
}
