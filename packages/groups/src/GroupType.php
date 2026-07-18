<?php

declare(strict_types=1);

namespace Waaseyaa\Groups;

use Waaseyaa\Entity\ConfigEntityBase;

/**
 * Config entity declaring a Group bundle (e.g. 'business', 'organization').
 *
 * Holds only bundle identity; bundle-scoped field definitions are registered
 * against the group entity type via EntityTypeManager::addBundleFields().
 */
final class GroupType extends ConfigEntityBase
{
    protected string $entityTypeId = 'group_type';

    protected array $entityKeys = [
        'id' => 'id',
        'label' => 'label',
    ];

    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see EntityBase::duplicateInstance()}.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
    ) {
        if (!array_key_exists('description', $values)) {
            $values['description'] = '';
        }

        $entityTypeId = $entityTypeId !== '' ? $entityTypeId : $this->entityTypeId;
        $entityKeys = $entityKeys !== [] ? $entityKeys : $this->entityKeys;

        parent::__construct($values, $entityTypeId, $entityKeys);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getDescription(): string
    {
        return (string) ($this->get('description') ?? '');
    }

    public function setDescription(string $description): static
    {
        $this->set('description', $description);

        return $this;
    }
}
