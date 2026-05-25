<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Version;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * Media version entity — one row per blob write (CAS-style, content-addressed).
 *
 * Each save of a Media entity that carries a new upload creates one MediaVersion
 * row. Identical content (same sha256) shares the same blob_uri — CAS
 * deduplication. The vid counter is monotonically increasing per media_uuid.
 *
 * Fields:
 *   - id          AUTO-INCREMENT surrogate PK (internal)
 *   - uuid        RFC-4122 UUID, public stable identifier
 *   - media_uuid  FK → media.uuid (the parent media entity)
 *   - vid         monotonic integer version counter, per-media_uuid
 *   - blob_uri    content-addressed URI (scheme: cas://<sha256>)
 *   - mime        MIME type as declared at upload time
 *   - size        byte length of the blob
 *   - sha256      hex-encoded SHA-256 of the raw blob bytes
 *   - created_at  Unix timestamp (seconds)
 *   - created_by  UID of the account that created the version (0 = anonymous)
 *
 * @api
 */
final class MediaVersion extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct(
            $values,
            $entityTypeId !== '' ? $entityTypeId : 'media_version',
            $entityKeys !== [] ? $entityKeys : ['id' => 'id', 'uuid' => 'uuid'],
            $fieldDefinitions,
        );
    }

    public function mediaUuid(): string
    {
        return (string) ($this->values['media_uuid'] ?? '');
    }

    public function vid(): int
    {
        return (int) ($this->values['vid'] ?? 0);
    }

    public function blobUri(): string
    {
        return (string) ($this->values['blob_uri'] ?? '');
    }

    public function mime(): string
    {
        return (string) ($this->values['mime'] ?? '');
    }

    public function size(): int
    {
        return (int) ($this->values['size'] ?? 0);
    }

    public function sha256(): string
    {
        return (string) ($this->values['sha256'] ?? '');
    }

    public function createdAt(): int
    {
        return (int) ($this->values['created_at'] ?? 0);
    }

    public function createdBy(): int
    {
        return (int) ($this->values['created_by'] ?? 0);
    }
}
