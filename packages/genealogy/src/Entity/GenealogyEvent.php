<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'genealogy_event', label: 'Genealogy event', description: 'A vital or narrative event', api: true)]
#[ContentEntityKeys(label: 'display_name')]
final class GenealogyEvent extends ContentEntityBase
{
    #[Field(label: 'Display name', required: true, settings: ['weight' => 0], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $display_name = '';

    #[Field(label: 'Event type', settings: ['weight' => 1], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?string $event_type = null;

    #[Field(label: 'Event date', settings: ['weight' => 2], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?string $event_date = null;

    #[Field(type: 'integer', label: 'Tree', settings: ['weight' => 3, 'not_null' => false, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $tree_id = null;

    #[Field(label: 'Deleted at', default: '', settings: ['weight' => 9, 'length' => 32], read: \Waaseyaa\Entity\FieldReadLevel::Internal)]
    public string $deleted_at = '';

    #[Field(type: 'boolean', label: 'Published', default: false, settings: ['weight' => 10, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public bool $status = false;
}
