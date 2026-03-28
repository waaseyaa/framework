<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class UploadHandler
{
    public const int DEFAULT_MAX_SIZE = 5_242_880; // 5MB

    public const array DEFAULT_ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** @param string[] $allowedMimeTypes */
    public function __construct(
        private readonly string $basePath,
        private readonly array $allowedMimeTypes = self::DEFAULT_ALLOWED_TYPES,
        private readonly int $maxSizeBytes = self::DEFAULT_MAX_SIZE,
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

        if (!in_array($file['type'] ?? '', $this->allowedMimeTypes, true)) {
            $errors[] = 'File type not allowed.';
        }

        return $errors;
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
        $targetDir = $this->basePath . '/' . $subdir;

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = $this->generateSafeFilename($file['name'] ?? 'upload.bin');
        $targetPath = $targetDir . '/' . $filename;
        move_uploaded_file($file['tmp_name'], $targetPath);

        return $subdir . '/' . $filename;
    }

    public function deleteDirectory(string $subdir): void
    {
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
}
