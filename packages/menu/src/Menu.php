<?php

declare(strict_types=1);

namespace Aurora\Menu;

use Aurora\Entity\ConfigEntityBase;

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
     */
    public function __construct(array $values = [])
    {
        if (\array_key_exists('description', $values)) {
            $this->description = (string) $values['description'];
        }

        if (\array_key_exists('locked', $values)) {
            $this->locked = (bool) $values['locked'];
        }

        parent::__construct(
            values: $values,
            entityTypeId: 'menu',
            entityKeys: ['id' => 'id', 'label' => 'label'],
        );
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
        $this->values['description'] = $description;

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
        $this->values['locked'] = $locked;

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
