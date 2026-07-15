<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'genealogy_family', label: 'Genealogy family', description: 'A family or household group', api: true)]
#[ContentEntityKeys(label: 'display_name')]
final class GenealogyFamily extends ContentEntityBase
{
    #[Field(label: 'Display name', required: true, settings: ['weight' => 0])]
    public string $display_name = '';

    #[Field(type: 'integer', label: 'Tree', settings: ['weight' => 1, 'not_null' => false])]
    public ?int $tree_id = null;

    #[Field(label: 'Deleted at', default: '', settings: ['weight' => 9, 'length' => 32])]
    public string $deleted_at = '';

    #[Field(type: 'boolean', label: 'Published', default: false, settings: ['weight' => 10])]
    public bool $status = false;
}
