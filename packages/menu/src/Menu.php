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

        // #2755: materialize the key into $values (not a raw property) before
        // sealing — sealed V2 hydration (EntityInstantiator::instantiateSealed())
        // bypasses this constructor entirely for entities loaded from storage,
        // via ReflectionClass::newInstanceWithoutConstructor(). A value tracked
        // only on a protected property set here would silently reset to its
        // class default on every reload, which is exactly how the locked flag
        // stopped being read at all. isLocked()/setLocked() below go through
        // the value container (get()/set()) so both fresh and hydrated Menu
        // objects agree.
        if (!\array_key_exists('locked', $values)) {
            $values['locked'] = false;
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
     *
     * System menus like 'main' are typically locked.
     */
    public function isLocked(): bool
    {
        return (bool) ($this->get('locked') ?? false);
    }

    /**
     * Set whether this menu is locked.
     */
    public function setLocked(bool $locked): static
    {
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
        $config['locked'] = $this->isLocked();

        return $config;
    }
}
