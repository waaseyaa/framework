<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

/**
 * Filesystem-backed file repository.
 *
 * Persists file metadata as JSON sidecar files under a configurable root.
 * Paths are derived from file URIs, so storage is organized into URI-based
 * subdirectories (for example: public://images/photo.jpg).
 * @api
 */
final class LocalFileRepository implements FileRepositoryInterface
{
    public function __construct(
        private readonly string $rootDir,
    ) {
        if (!is_dir($this->rootDir) && !mkdir($this->rootDir, 0o755, true) && !is_dir($this->rootDir)) {
            throw new \RuntimeException(sprintf('Unable to create files root directory: %s', $this->rootDir));
        }
    }

    public function save(File $file): File
    {
        $metadataPath = $this->resolveMetadataPath($file->uri);
        $directory = dirname($metadataPath);

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
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
            'originalName' => $file->originalName,
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
            originalName: isset($data['originalName']) ? (string) $data['originalName'] : null,
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
                originalName: isset($data['originalName']) ? (string) $data['originalName'] : null,
            );
        }

        usort($result, fn(File $a, File $b): int => strcmp($a->uri, $b->uri));

        return $result;
    }

    /**
     * Derives the sidecar path for a file URI.
     *
     * Stream-wrapper URIs such as `public://images/shared.pdf` are not real
     * hierarchical URLs: `parse_url()` still splits them into a `host`
     * (`images`) and a `path` (`/shared.pdf`) per RFC 3986 grammar. Using
     * only `scheme` + `path`, as this method previously did, silently
     * discards that `host` segment, so `public://images/shared.pdf` and
     * `public://docs/shared.pdf` — two distinct, documented URIs — collided
     * on the same `.../shared.pdf.meta.json` sidecar (#2758). Every segment
     * after `scheme://` is therefore treated as one flat, ordered path and
     * carried into the sidecar location, preserving full URI identity.
     *
     * This changes the on-disk sidecar layout for any URI that has more
     * than one segment after the scheme (i.e. anything but a bare
     * `scheme://file` root URI). Existing installs upgrading past this fix
     * will not find previously-saved metadata for such URIs at their old
     * (collision-prone) location; there is deliberately no automatic read
     * fallback to that old path, because a fallback would itself have to
     * pick a winner among the URIs that used to alias there — precisely the
     * silent-data-loss failure mode this fix removes. Reconciling
     * already-collided sidecars from a prior install is a data-migration
     * concern, not something this pure path-derivation method can safely
     * infer, and is intentionally left to separate migration tooling.
     */
    private function resolveMetadataPath(string $uri): string
    {
        $scheme = 'public';
        $rest = $uri;

        if (preg_match('#^([A-Za-z][A-Za-z0-9+.-]*)://(.*)$#s', $uri, $matches) === 1) {
            $scheme = $this->sanitizeSegment($matches[1]);
            $rest = $matches[2];
        }

        $segments = array_filter(explode('/', trim($rest, '/')), static fn(string $segment): bool => $segment !== '');
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
