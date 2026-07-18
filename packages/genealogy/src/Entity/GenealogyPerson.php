<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

#[ContentEntityType(id: 'genealogy_person', label: 'Genealogy person', description: 'An individual in a genealogy dataset', api: true)]
#[ContentEntityKeys(label: 'display_name')]
final class GenealogyPerson extends ContentEntityBase
{
    #[Field(label: 'Display name', required: true, settings: ['weight' => 0], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public string $display_name = '';

    #[Field(label: 'Given name', settings: ['weight' => 1], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?string $given_name = null;

    #[Field(label: 'Family name', settings: ['weight' => 2], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?string $family_name = null;

    #[Field(label: 'Birth date', settings: ['weight' => 3], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?string $birth_date = null;

    #[Field(label: 'Death date', settings: ['weight' => 4, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?string $death_date = null;

    #[Field(type: 'boolean', label: 'Living (manual)', description: 'When true, stricter visibility applies for non-owners. Default true (conservative) when dates are unknown.', default: true, settings: ['weight' => 5, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public bool $is_living = true;

    #[Field(type: 'integer', label: 'Tree', description: 'Owning genealogy_tree entity id.', settings: ['weight' => 6, 'not_null' => false, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public ?int $tree_id = null;

    #[Field(label: 'Deleted at', description: 'Non-empty ISO-ish tombstone timestamp when soft-deleted.', default: '', settings: ['weight' => 9, 'length' => 32], read: \Waaseyaa\Entity\FieldReadLevel::Internal)]
    public string $deleted_at = '';

    #[Field(type: 'boolean', label: 'Published', default: false, settings: ['weight' => 10, 'authorizationInput' => true], read: \Waaseyaa\Entity\FieldReadLevel::Protected)]
    public bool $status = false;
}
