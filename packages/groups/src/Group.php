<?php

declare(strict_types=1);

namespace Waaseyaa\Groups;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldStorage;

/**
 * Multi-bundle Group content entity.
 *
 * Bundle partitioning is provided by GroupType. Bundle-scoped field values are
 * stored in per-bundle subtables (`group__{bundle}`); core keys live on the
 * base `group` table. See docs/specs/bundle-scoped-storage.md.
 */
#[ContentEntityType(id: 'group', label: 'Group', description: 'Multi-bundle group of members, actors, or subjects.', api: false)]
#[ContentEntityKeys(id: 'gid', uuid: 'uuid', bundle: 'type', label: 'name', langcode: 'langcode')]
final class Group extends ContentEntityBase
{
    #[Field(type: 'integer', default: 1, label: 'Status', description: 'Whether the group is published.', stored: FieldStorage::Data)]
    public ?int $status = null;

    #[Field(type: 'integer', label: 'Created at', stored: FieldStorage::Data)]
    public ?int $created_at = null;

    #[Field(type: 'integer', label: 'Updated at', stored: FieldStorage::Data)]
    public ?int $updated_at = null;

    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param string $entityTypeId Override machine name (defaults to `group` when empty).
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see ContentEntityBase::duplicateInstance()}.
     * @param array<string, FieldDefinitionInterface> $fieldDefinitions Field definitions keyed by field name.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    public function getName(): string
    {
        return $this->label();
    }

    public function setName(string $name): static
    {
        return $this->set('name', $name);
    }

    /**
     * Returns the group_type bundle id this group belongs to.
     */
    public function getGroupTypeId(): string
    {
        return $this->bundle();
    }
}
