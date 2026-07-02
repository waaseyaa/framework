<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @api
 */
final class UploadHandler
{
    public const int DEFAULT_MAX_SIZE = 5_242_880; // 5MB

    public const array DEFAULT_ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * @param string[] $allowedMimeTypes Exact types or `type/*` wildcards.
     * @param ?\Closure(string): ?string $mimeDetector Overrides content
     *        sniffing (testing seam). Default: ext-fileinfo.
     */
    public function __construct(
        private readonly string $basePath,
        private readonly array $allowedMimeTypes = self::DEFAULT_ALLOWED_TYPES,
        private readonly int $maxSizeBytes = self::DEFAULT_MAX_SIZE,
        private readonly ?\Closure $mimeDetector = null,
    ) {}

    /** @return string[] validation errors */
    public function validate(array $file): array
    {
        $errors = [];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed.';

            return $errors;
        }

        if (($file['size'] ?? 0) > $this->maxSizeBytes) {
            $maxMb = round($this->maxSizeBytes / 1_048_576);
            $errors[] = "File must be under {$maxMb}MB.";
        }

        // Fail closed: only a content sniff counts. The client-declared
        // 'type' is attacker-controlled and is never consulted.
        $detectedType = $this->detectMimeType((string) ($file['tmp_name'] ?? ''));
        if ($detectedType === null) {
            $errors[] = 'File type could not be verified.';

            return $errors;
        }

        if (!self::mimeTypeMatches($detectedType, $this->allowedMimeTypes)) {
            $errors[] = 'File type not allowed.';
        }

        return $errors;
    }

    /**
     * Sniff a file's MIME type from its contents (ext-fileinfo).
     *
     * Returns null when the file is missing or the type cannot be
     * determined — callers MUST treat null as "reject" (fail closed),
     * never fall back to a client-declared type.
     */
    public function detectMimeType(string $filePath): ?string
    {
        if ($this->mimeDetector !== null) {
            return ($this->mimeDetector)($filePath);
        }

        if ($filePath === '' || !is_file($filePath) || !class_exists(\finfo::class)) {
            return null;
        }

        $detected = new \finfo(FILEINFO_MIME_TYPE)->file($filePath);

        return is_string($detected) && $detected !== '' ? $detected : null;
    }

    /**
     * Match a MIME type against an allowlist of exact types and
     * `type/*` wildcards. Shared by UploadHandler::validate() and
     * MediaRouter so there is exactly one matcher.
     *
     * @param list<string> $allowedMimeTypes
     */
    public static function mimeTypeMatches(string $mimeType, array $allowedMimeTypes): bool
    {
        foreach ($allowedMimeTypes as $allowed) {
            if ($allowed === $mimeType) {
                return true;
            }
            if (str_ends_with($allowed, '/*')) {
                $prefix = substr($allowed, 0, -1);
                if (str_starts_with($mimeType, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function generateSafeFilename(string $original): string
    {
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $name = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
        $safe = trim($safe, '_');

        if ($safe === '') {
            $safe = 'upload';
        }

        return $safe . '_' . bin2hex(random_bytes(4)) . '.' . ($ext ?: 'bin');
    }

    /** @return string relative path from basePath */
    public function moveUpload(array $file, string $subdir): string
    {
        $this->assertSafeSubdir($subdir);

        $errors = $this->validate($file);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $targetDir = $this->basePath . '/' . $subdir;

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0o755, true);
        }

        $filename = $this->generateSafeFilename($file['name'] ?? 'upload.bin');
        $targetPath = $targetDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new \RuntimeException('Failed to move uploaded file.');
        }

        return $subdir . '/' . $filename;
    }

    public function deleteDirectory(string $subdir): void
    {
        $this->assertSafeSubdir($subdir);

        $dir = $this->basePath . '/' . $subdir;

        if (!is_dir($dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }

    private function assertSafeSubdir(string $subdir): void
    {
        if (str_contains($subdir, '..')) {
            throw new \InvalidArgumentException('Invalid subdirectory: path traversal not allowed.');
        }
    }
}
