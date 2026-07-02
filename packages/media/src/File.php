<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

/**
 * Value object representing a managed file.
 *
 * Files are immutable data objects that describe a file resource by its URI,
 * filename, MIME type, and other metadata. File objects do not perform I/O;
 * they are pure data carriers used by file repositories and media entities.
 * @api
 */
final readonly class File
{
    /**
     * @param ?string $originalName The client-supplied filename, verbatim.
     *        The disk-facing $filename is ASCII-sanitized (destroying
     *        Indigenous orthography); this field preserves the original as
     *        display metadata. Never use it to build filesystem paths.
     */
    public function __construct(
        public string $uri,
        public string $filename,
        public string $mimeType = 'application/octet-stream',
        public int $size = 0,
        public string $status = 'permanent',
        public ?int $ownerId = null,
        public ?int $createdTime = null,
        public ?string $originalName = null,
    ) {}

    /**
     * Gets the file extension from the filename.
     */
    public function getExtension(): string
    {
        return pathinfo($this->filename, PATHINFO_EXTENSION);
    }

    /**
     * Determines whether this file is an image based on its MIME type.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }
}
