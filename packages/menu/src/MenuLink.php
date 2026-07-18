<?php

declare(strict_types=1);

namespace Waaseyaa\Menu;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Represents a menu link content entity.
 *
 * Menu links are individual navigation items that belong to a menu.
 * They can be organized hierarchically via parent/child relationships.
 */
#[ContentEntityType(id: 'menu_link', api: true)]
#[ContentEntityKeys(label: 'title', bundle: 'menu_name')]
final class MenuLink extends ContentEntityBase
{
    #[Field(required: false, label: 'Title', read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $title = '';

    #[Field(required: false, label: 'URL', read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $url = '';

    #[Field(required: false, label: 'Menu', read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public string $menu_name = '';

    #[Field(type: 'string', required: false, label: 'Parent', read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public int|string|null $parent_id = null;

    #[Field(type: 'integer', required: false, label: 'Weight', read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public int $weight = 0;

    #[Field(type: 'boolean', required: false, label: 'Enabled', read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public bool $enabled = true;

    #[Field(type: 'boolean', required: false, label: 'Expanded', read: \Waaseyaa\Entity\FieldReadLevel::Public)]
    public bool $expanded = false;

    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see ContentEntityBase::duplicateInstance()}.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        // Set defaults for optional values.
        $values += [
            'enabled' => true,
            'expanded' => false,
            'weight' => 0,
            'parent_id' => null,
        ];

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    /**
     * Get the link title (display text).
     */
    public function getTitle(): string
    {
        return (string) ($this->get('title') ?? '');
    }

    /**
     * Set the link title.
     */
    public function setTitle(string $title): static
    {
        $this->set('title', $title);

        return $this;
    }

    /**
     * Get the link URL or route path.
     */
    public function getUrl(): string
    {
        return (string) ($this->get('url') ?? '');
    }

    /**
     * Set the link URL or route path.
     */
    public function setUrl(string $url): static
    {
        $this->set('url', $url);

        return $this;
    }

    /**
     * Get the menu name this link belongs to (the bundle).
     */
    public function getMenuName(): string
    {
        return $this->bundle();
    }

    /**
     * Get the parent menu link ID for hierarchy.
     */
    public function getParentId(): int|string|null
    {
        return $this->get('parent_id');
    }

    /**
     * Set the parent menu link ID for hierarchy.
     */
    public function setParentId(int|string|null $parentId): static
    {
        $this->set('parent_id', $parentId);

        return $this;
    }

    /**
     * Get the sort weight.
     */
    public function getWeight(): int
    {
        return (int) ($this->get('weight') ?? 0);
    }

    /**
     * Set the sort weight.
     */
    public function setWeight(int $weight): static
    {
        $this->set('weight', $weight);

        return $this;
    }

    /**
     * Whether this link is visible/enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->get('enabled') ?? true);
    }

    /**
     * Set whether this link is enabled.
     */
    public function setEnabled(bool $enabled): static
    {
        $this->set('enabled', $enabled);

        return $this;
    }

    /**
     * Whether children of this link are shown by default.
     */
    public function isExpanded(): bool
    {
        return (bool) ($this->get('expanded') ?? false);
    }

    /**
     * Set whether children are shown by default.
     */
    public function setExpanded(bool $expanded): static
    {
        $this->set('expanded', $expanded);

        return $this;
    }

    /**
     * Whether this link points to an external URL.
     */
    public function isExternal(): bool
    {
        $url = $this->getUrl();

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    /**
     * Whether this link is a root-level item (no parent).
     */
    public function isRoot(): bool
    {
        $parentId = $this->getParentId();

        return $parentId === null || $parentId === 0;
    }
}
