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

        $this->writeAtomically($metadataPath, $payload);

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
     * Detects and relocates sidecars sitting at the pre-#2758 collision-prone
     * layout to the location their own recorded `uri` resolves to under the
     * current layout.
     *
     * #2758's fix changed how {@see resolveMetadataPath()} derives a
     * sidecar's on-disk location for any URI with more than one segment
     * after the scheme, so an existing install upgrading past that fix has
     * previously-saved metadata sitting at a now-stale path. This method is
     * the migration/reconciliation procedure for that: it scans every
     * `*.meta.json` sidecar under {@see $rootDir}, reads the `uri` each one
     * recorded at save time, and recomputes where that URI belongs under the
     * current layout.
     *
     * - If the sidecar is already at its current-layout location, it is left
     *   untouched and omitted from the report.
     * - If it is not, and nothing already exists at the current-layout
     *   location, the sidecar is relocated there (`relocated`). This is the
     *   common case: the URI never collided with another one, or it is the
     *   sole survivor of a pre-fix collision (the last writer under the old
     *   scheme) — either way there is exactly one candidate on disk, so
     *   relocating it chooses nothing between competing data.
     * - If something already exists at the current-layout location — for
     *   example a fresh sidecar written by post-fix code for that same URI —
     *   the legacy sidecar is left in place untouched and reported as a
     *   `conflict` (`unreadable` covers a legacy file whose JSON payload
     *   cannot be decoded or carries no `uri`). Two live candidates *is* a
     *   real choice, and per #2758's acceptance criteria this method must
     *   never silently pick a winner — operators resolve `conflict` entries
     *   by hand.
     *
     * Idempotent: a second run over an already-reconciled tree reports
     * nothing to do.
     *
     * @return array<int, array{uri: string, from: string, to: string, action: 'relocated'|'conflict'|'unreadable'}>
     * @api
     */
    public function reconcileLegacySidecars(): array
    {
        $report = [];

        if (!is_dir($this->rootDir)) {
            return $report;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->rootDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        // Collect the full candidate list before mutating anything: renaming
        // files while a RecursiveDirectoryIterator is still walking the same
        // tree has undefined visitation order and can skip or revisit
        // entries.
        $sidecarPaths = [];
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), '.meta.json')) {
                $sidecarPaths[] = $fileInfo->getPathname();
            }
        }

        foreach ($sidecarPaths as $sourcePath) {
            $uri = $this->readSidecarUri($sourcePath);
            if ($uri === null) {
                $report[] = ['uri' => '', 'from' => $sourcePath, 'to' => '', 'action' => 'unreadable'];
                continue;
            }

            $targetPath = $this->resolveMetadataPath($uri);
            if ($targetPath === $sourcePath) {
                continue;
            }

            if (is_file($targetPath)) {
                $report[] = ['uri' => $uri, 'from' => $sourcePath, 'to' => $targetPath, 'action' => 'conflict'];
                continue;
            }

            $targetDirectory = dirname($targetPath);
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0o755, true) && !is_dir($targetDirectory)) {
                throw new \RuntimeException(sprintf('Unable to create file metadata directory: %s', $targetDirectory));
            }

            if (!rename($sourcePath, $targetPath)) {
                throw new \RuntimeException(sprintf('Unable to relocate file metadata: %s -> %s', $sourcePath, $targetPath));
            }

            $report[] = ['uri' => $uri, 'from' => $sourcePath, 'to' => $targetPath, 'action' => 'relocated'];
        }

        return $report;
    }

    private function readSidecarUri(string $sidecarPath): ?string
    {
        $raw = file_get_contents($sidecarPath);
        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || !isset($data['uri']) || !is_string($data['uri']) || $data['uri'] === '') {
            return null;
        }

        return $data['uri'];
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
     * (collision-prone) location at load()/delete() time; there is
     * deliberately no automatic read fallback to that old path, because a
     * fallback would itself have to pick a winner among the URIs that used
     * to alias there — precisely the silent-data-loss failure mode this fix
     * removes. {@see reconcileLegacySidecars()} is the migration/
     * reconciliation procedure for bringing an existing install's
     * previously-saved sidecars forward to this layout: an operator runs it
     * once after upgrading, and it relocates unambiguous cases automatically
     * while reporting (never silently resolving) genuine conflicts.
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

    /**
     * Writes $payload to $path via write-to-temp-then-rename.
     *
     * A direct file_put_contents() to an existing sidecar truncates it in
     * place: a concurrent load() that opens the file between the truncate
     * and the rewrite observes a partial (and possibly non-JSON-decodable)
     * body. Writing the full payload to a temp file beside $path and then
     * rename()-ing it into place instead makes the replacement a single
     * atomic directory-entry swap — a concurrent reader always sees either
     * the complete old sidecar or the complete new one, never a partial one.
     * The temp file is created in the same directory as $path so the rename
     * stays on one filesystem, and is removed on any failure so an
     * interrupted write leaves nothing behind.
     */
    private function writeAtomically(string $path, string $payload): void
    {
        $directory = dirname($path);
        $temporary = tempnam($directory, '.meta-');
        if (!is_string($temporary)) {
            throw new \RuntimeException(sprintf('Unable to create a temporary file beside %s.', $path));
        }

        try {
            if (file_put_contents($temporary, $payload) !== strlen($payload)) {
                throw new \RuntimeException(sprintf('Unable to write file metadata: %s', $path));
            }

            if (!rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Unable to move file metadata into place: %s', $path));
            }
        } catch (\Throwable $exception) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw $exception;
        }
    }
}
