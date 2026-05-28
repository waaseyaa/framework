<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * The Attachment content entity.
 *
 * Represents a file attachment linked to a parent entity. Enforces the
 * at-most-one-active invariant via {@see AttachmentRepository::setActive()}.
 *
 * Fields stored in the _data JSON blob: filename, content_type, size,
 * storage_uri, checksum.
 */
#[ContentEntityType(id: 'attachment', label: 'Attachment', description: 'File attachment linked to a parent entity.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'filename')]
final class Attachment extends ContentEntityBase
{
    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via duplicateInstance().
     * @param array<string, mixed> $fieldDefinitions Field definitions (injected by EntityInstantiator).
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = 'attachment',
        array $entityKeys = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'filename'],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
