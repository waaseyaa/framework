<?php

declare(strict_types=1);

namespace Waaseyaa\Menu;

use Waaseyaa\Entity\ConfigEntityBase;

/**
 * Represents a menu configuration entity.
 *
 * Menus are containers that hold menu links. Examples include
 * 'main' (primary navigation), 'footer', 'admin', etc.
 */
final class Menu extends ConfigEntityBase
{
    /**
     * A description of the menu.
     */
    protected string $description = '';

    /**
     * Whether this menu is locked (cannot be deleted).
     *
     * System menus like 'main' are typically locked.
     */
    protected bool $locked = false;

    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see EntityBase::duplicateInstance()}.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
    ) {
        if (\array_key_exists('description', $values)) {
            $this->description = (string) $values['description'];
        }

        if (\array_key_exists('locked', $values)) {
            $this->locked = (bool) $values['locked'];
        }

        $entityTypeId = $entityTypeId !== '' ? $entityTypeId : 'menu';
        $entityKeys = $entityKeys !== [] ? $entityKeys : ['id' => 'id', 'label' => 'label'];

        parent::__construct($values, $entityTypeId, $entityKeys);
    }

    /**
     * Get the menu description.
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Set the menu description.
     */
    public function setDescription(string $description): static
    {
        $this->description = $description;
        $this->set('description', $description);

        return $this;
    }

    /**
     * Whether this menu is locked (cannot be deleted).
     */
    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * Set whether this menu is locked.
     */
    public function setLocked(bool $locked): static
    {
        $this->locked = $locked;
        $this->set('locked', $locked);

        return $this;
    }

    /**
     * Returns an array suitable for config export.
     */
    public function toConfig(): array
    {
        $config = parent::toConfig();
        $config['description'] = $this->description;
        $config['locked'] = $this->locked;

        return $config;
    }
}
