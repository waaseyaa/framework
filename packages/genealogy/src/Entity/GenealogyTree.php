<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'genealogy_tree', label: 'Genealogy tree', description: 'Tenancy root for genealogy workspace (owner, grants, community overlays).', api: true)]
#[ContentEntityKeys(label: 'display_name')]
final class GenealogyTree extends ContentEntityBase
{
    #[Field(label: 'Display name', description: 'Human-facing tree title.', required: true, settings: ['weight' => 0])]
    public string $display_name = '';

    #[Field(type: 'integer', label: 'Owner user ID', description: 'Account that owns this tree workspace.', required: true, settings: ['weight' => 1, 'not_null' => true])]
    public int $owner_uid = 0;

    #[Field(type: 'boolean', label: 'Published', description: 'Workflow-style visibility flag; defaults off (private-by-default).', required: false, default: false, settings: ['weight' => 10])]
    public bool $status = false;
}
