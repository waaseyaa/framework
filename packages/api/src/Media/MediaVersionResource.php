<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Media;

/**
 * Immutable DTO representing a single MediaVersion API resource.
 *
 * Property names follow camelCase (JSON:API attribute convention for the SPA).
 * Maps from MediaVersion entity fields — see packages/media/src/Version/MediaVersion.php.
 *
 * @internal Parked until #1742's byte-persistence criterion is met.
 */
final readonly class MediaVersionResource
{
    public function __construct(
        public int $vid,
        public string $mediaUuid,
        public string $blobUri,
        public string $mime,
        public int $sizeBytes,
        public string $sha256,
        public int $createdAt,
        public int $createdBy,
    ) {}

    /**
     * Serialise to an array suitable for JSON:API `data.attributes`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'vid' => $this->vid,
            'media_uuid' => $this->mediaUuid,
            'blob_uri' => $this->blobUri,
            'mime' => $this->mime,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
            'created_at' => $this->createdAt,
            'created_by' => $this->createdBy,
        ];
    }
}
