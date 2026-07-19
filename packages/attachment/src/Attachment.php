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
 * entity hydration/validation layer treats them as real, typed properties —
 * `AttachmentRepository` reads and updates them as real columns (e.g.
 * `setActive()`). NOTE: for this entity type's `sql-blob` storage backend,
 * the GENERIC entity-storage schema-sync path (`SqlSchemaHandler`) does NOT
 * materialize entity-level `#[Field]` columns from these attributes alone —
 * that only happens for the `sql-column` backend. The columns these three
 * fields need (plus `created_at`/`updated_at` and the composite/partial
 * indexes) are materialized by this package's own
 * {@see \Waaseyaa\Attachment\Schema\AttachmentSchema}, wired into every
 * kernel boot by {@see \Waaseyaa\Attachment\AttachmentServiceProvider::boot()}.
 * See {@see \Waaseyaa\Attachment\Schema\AttachmentSchema} for the full
 * canonical-shape rationale. Other descriptive fields (filename, content_type,
 * size, storage_uri, checksum) live in the `_data` JSON blob.
 */
#[ContentEntityType(id: 'attachment', label: 'Attachment', description: 'File attachment linked to a parent entity.', api: true)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'filename')]
final class Attachment extends ContentEntityBase
{
    #[Field(required: false, label: 'Filename', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $filename = '';

    #[Field(required: false, label: 'Content type', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $content_type = '';

    #[Field(type: 'integer', required: false, label: 'Size', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public int $size = 0;

    #[Field(required: false, label: 'Storage URI', read: \Waaseyaa\Entity\FieldReadLevel::Internal)]
    public string $storage_uri = '';

    #[Field(required: false, label: 'Checksum', read: \Waaseyaa\Entity\FieldReadLevel::Internal)]
    public string $checksum = '';

    #[Field(type: 'integer', required: false, label: 'Created', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $created_at = null;

    #[Field(type: 'integer', required: false, label: 'Updated', read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $updated_at = null;

    #[Field(label: 'Parent Entity Type', settings: ['weight' => 1, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $parent_entity_type = '';

    #[Field(label: 'Parent Entity ID', settings: ['weight' => 2, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $parent_entity_id = '';

    #[Field(type: 'boolean', label: 'Active', default: 0, settings: ['weight' => 3], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
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
