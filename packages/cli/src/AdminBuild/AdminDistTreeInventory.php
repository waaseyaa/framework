<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

/**
 * Deterministic, order-independent inventory of one generated tree.
 *
 * The published tree (packages/admin-surface/dist) and the broader
 * intermediate Nuxt output (packages/admin/.output) are inventoried by the
 * same code but never conflated: each carries its own digest and counts.
 *
 * @api Consumed by bin/admin-dist-acceptance, outside the analysed path set.
 */
final readonly class AdminDistTreeInventory
{
    /** @param array<string, array{size: int, sha256: string}> $entries relative path => metadata, sorted */
    private function __construct(
        public array $entries,
        public string $digest,
        public int $fileCount,
        public int $byteCount,
    ) {}

    public static function scan(string $root, bool $refuseSymlinks = true): self
    {
        $real = realpath($root);
        if (!is_string($real) || !is_dir($real)) {
            throw new AdminDistAcceptanceException('tree-root-invalid', [$root]);
        }
        $real = rtrim($real, DIRECTORY_SEPARATOR);

        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                if ($refuseSymlinks) {
                    throw new AdminDistAcceptanceException('tree-symlink-refused', [$entry->getPathname()]);
                }
                continue;
            }
            if (!$entry->isFile()) {
                continue;
            }
            $absolute = $entry->getPathname();
            $hash = hash_file('sha256', $absolute);
            $size = filesize($absolute);
            if (!is_string($hash) || !is_int($size)) {
                throw new AdminDistAcceptanceException('tree-file-unreadable', [$absolute]);
            }
            $relative = str_replace('\\', '/', substr($absolute, strlen($real) + 1));
            $entries[$relative] = ['size' => $size, 'sha256' => $hash];
        }
        ksort($entries, SORT_STRING);

        $roster = [];
        $bytes = 0;
        foreach ($entries as $relative => $meta) {
            $bytes += $meta['size'];
            $roster[] = $relative . "\0" . $meta['size'] . "\0" . $meta['sha256'];
        }

        return new self(
            entries: $entries,
            digest: hash('sha256', implode("\n", $roster)),
            fileCount: count($entries),
            byteCount: $bytes,
        );
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->entries);
    }

    /**
     * Relative paths present here whose bytes differ from (or are absent in)
     * the other inventory. Used both for reproducibility evidence and for the
     * changed-path inventory the acceptance manifest records.
     *
     * @return list<string>
     */
    public function differingPaths(self $other): array
    {
        $differing = [];
        foreach ($this->entries as $relative => $meta) {
            if (($other->entries[$relative]['sha256'] ?? null) !== $meta['sha256']) {
                $differing[] = $relative;
            }
        }
        foreach ($other->entries as $relative => $meta) {
            if (!isset($this->entries[$relative])) {
                $differing[] = $relative;
            }
        }
        sort($differing, SORT_STRING);

        return array_values(array_unique($differing));
    }
}
