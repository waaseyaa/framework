<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * The Attachment content entity.
 *
 * Represents a file attachment linked to a parent entity. Enforces the
 * at-most-one-active invariant via {@see AttachmentRepository::setActive()}.
 *
 * The parent linkage and active flag are declared as `#[Field]`s so the
 * framework's schema-sync materializes their columns — `AttachmentRepository`
 * reads and updates them as real columns (e.g. `setActive()`). Other
 * descriptive fields (filename, content_type, size, storage_uri, checksum)
 * live in the `_data` JSON blob.
 */
#[ContentEntityType(id: 'attachment', label: 'Attachment', description: 'File attachment linked to a parent entity.')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'filename')]
final class Attachment extends ContentEntityBase
{
    #[Field(label: 'Parent Entity Type', settings: ['weight' => 1])]
    public string $parent_entity_type = '';

    #[Field(label: 'Parent Entity ID', settings: ['weight' => 2])]
    public string $parent_entity_id = '';

    #[Field(type: 'boolean', label: 'Active', default: 0, settings: ['weight' => 3])]
    public bool $is_active = false;

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
