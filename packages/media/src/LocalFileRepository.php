<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

/**
 * Filesystem-backed file repository.
 *
 * Persists file metadata as JSON sidecar files under a configurable root.
 * Paths are derived from file URIs, so storage is organized into URI-based
 * subdirectories (for example: public://images/photo.jpg).
 */
final class LocalFileRepository implements FileRepositoryInterface
{
    public function __construct(
        private readonly string $rootDir,
    ) {
        if (!is_dir($this->rootDir) && !mkdir($this->rootDir, 0755, true) && !is_dir($this->rootDir)) {
            throw new \RuntimeException(sprintf('Unable to create files root directory: %s', $this->rootDir));
        }
    }

    public function save(File $file): File
    {
        $metadataPath = $this->resolveMetadataPath($file->uri);
        $directory = dirname($metadataPath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create file metadata directory: %s', $directory));
        }

        $payload = json_encode([
            'uri' => $file->uri,
            'filename' => $file->filename,
            'mimeType' => $file->mimeType,
            'size' => $file->size,
            'status' => $file->status,
            'ownerId' => $file->ownerId,
            'createdTime' => $file->createdTime,
        ], JSON_THROW_ON_ERROR);

        if (file_put_contents($metadataPath, $payload) === false) {
            throw new \RuntimeException(sprintf('Unable to write file metadata: %s', $metadataPath));
        }

        return $file;
    }

    public function load(string $uri): ?File
    {
        $metadataPath = $this->resolveMetadataPath($uri);
        if (!is_file($metadataPath)) {
            return null;
        }

        $raw = file_get_contents($metadataPath);
        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        return new File(
            uri: (string) ($data['uri'] ?? $uri),
            filename: (string) ($data['filename'] ?? basename($uri)),
            mimeType: (string) ($data['mimeType'] ?? 'application/octet-stream'),
            size: (int) ($data['size'] ?? 0),
            status: (string) ($data['status'] ?? 'permanent'),
            ownerId: isset($data['ownerId']) ? (int) $data['ownerId'] : null,
            createdTime: isset($data['createdTime']) ? (int) $data['createdTime'] : null,
        );
    }

    public function delete(string $uri): bool
    {
        $metadataPath = $this->resolveMetadataPath($uri);
        if (!is_file($metadataPath)) {
            return false;
        }

        return unlink($metadataPath);
    }

    public function findByOwner(int $ownerId): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->rootDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || !str_ends_with($fileInfo->getFilename(), '.meta.json')) {
                continue;
            }

            $raw = file_get_contents($fileInfo->getPathname());
            if ($raw === false) {
                continue;
            }

            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($data) || !isset($data['ownerId']) || (int) $data['ownerId'] !== $ownerId) {
                continue;
            }

            $result[] = new File(
                uri: (string) ($data['uri'] ?? ''),
                filename: (string) ($data['filename'] ?? ''),
                mimeType: (string) ($data['mimeType'] ?? 'application/octet-stream'),
                size: (int) ($data['size'] ?? 0),
                status: (string) ($data['status'] ?? 'permanent'),
                ownerId: (int) $data['ownerId'],
                createdTime: isset($data['createdTime']) ? (int) $data['createdTime'] : null,
            );
        }

        return $result;
    }

    private function resolveMetadataPath(string $uri): string
    {
        $parsed = parse_url($uri);
        $scheme = isset($parsed['scheme']) ? $this->sanitizeSegment($parsed['scheme']) : 'public';
        $path = isset($parsed['path']) ? trim($parsed['path'], '/') : trim($uri, '/');

        $segments = array_filter(explode('/', $path), static fn(string $segment): bool => $segment !== '');
        $safeSegments = array_map([$this, 'sanitizeSegment'], $segments);

        $target = implode('/', $safeSegments);
        if ($target === '') {
            $target = 'file';
        }

        return rtrim($this->rootDir, '/') . '/' . $scheme . '/' . $target . '.meta.json';
    }

    private function sanitizeSegment(string $segment): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $segment);
        if ($clean === null || $clean === '' || $clean === '.' || $clean === '..') {
            return '_';
        }

        return $clean;
    }
}
