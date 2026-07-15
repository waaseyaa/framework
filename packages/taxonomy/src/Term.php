<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Represents a taxonomy term within a vocabulary.
 *
 * Terms are content entities that belong to a vocabulary (the bundle).
 * They support hierarchical relationships through parent term IDs,
 * and can be published or unpublished.
 */
#[ContentEntityType(id: 'taxonomy_term', label: 'Taxonomy Term', description: 'Categorization terms for organizing content', api: true)]
#[ContentEntityKeys(id: 'tid', uuid: 'uuid', label: 'name', bundle: 'vid')]
final class Term extends ContentEntityBase
{
    #[Field(type: 'text', label: 'Description', description: 'A description of the term.', settings: ['weight' => 5])]
    public ?string $description = null;

    #[Field(type: 'string', label: 'Slug', description: 'URL-safe machine name for the term (e.g. imported from an external system\'s term slug).', settings: ['weight' => 6])]
    public ?string $slug = null;

    #[Field(type: 'integer', label: 'Weight', description: 'The weight of this term for ordering.', settings: ['weight' => 10])]
    public int $weight = 0;

    #[Field(type: 'entity_reference', label: 'Parent term', description: 'The parent term for hierarchical vocabularies.', settings: ['weight' => 15, 'target_entity_type_id' => 'taxonomy_term'])]
    public ?int $parent_id = null;

    #[Field(type: 'boolean', label: 'Published', description: 'Whether the term is published.', settings: ['weight' => 20])]
    public bool $status = true;
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
        // Ensure defaults for optional properties.
        if (!array_key_exists('description', $values)) {
            $values['description'] = '';
        }
        if (!array_key_exists('slug', $values)) {
            $values['slug'] = null;
        }
        if (!array_key_exists('weight', $values)) {
            $values['weight'] = 0;
        }
        if (!array_key_exists('parent_id', $values)) {
            $values['parent_id'] = null;
        }
        if (!array_key_exists('status', $values)) {
            $values['status'] = true;
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    /**
     * Returns the term name.
     */
    public function getName(): string
    {
        return $this->label();
    }

    /**
     * Sets the term name.
     */
    public function setName(string $name): static
    {
        return $this->set('name', $name);
    }

    /**
     * Returns the vocabulary ID (bundle) this term belongs to.
     */
    public function getVocabularyId(): string
    {
        return $this->bundle();
    }

    /**
     * Returns the term description.
     */
    public function getDescription(): string
    {
        return (string) ($this->get('description') ?? '');
    }

    /**
     * Sets the term description.
     */
    public function setDescription(string $description): static
    {
        return $this->set('description', $description);
    }

    /**
     * Returns the term slug (URL-safe machine name), or null if unset.
     *
     * Populated from external systems (e.g. WordPress term slugs) that key
     * terms by a stable, URL-safe identifier distinct from the display name.
     *
     * @api
     */
    public function getSlug(): ?string
    {
        $slug = $this->get('slug');

        return $slug === null ? null : (string) $slug;
    }

    /**
     * Sets the term slug.
     *
     * @api
     */
    public function setSlug(?string $slug): static
    {
        return $this->set('slug', $slug);
    }

    /**
     * Returns the sort weight.
     */
    public function getWeight(): int
    {
        return (int) ($this->get('weight') ?? 0);
    }

    /**
     * Sets the sort weight.
     */
    public function setWeight(int $weight): static
    {
        return $this->set('weight', $weight);
    }

    /**
     * Returns the parent term ID for hierarchy (null = root term).
     */
    public function getParentId(): ?int
    {
        $parentId = $this->get('parent_id');

        if ($parentId === null || $parentId === 0) {
            return $parentId === 0 ? 0 : null;
        }

        return (int) $parentId;
    }

    /**
     * Sets the parent term ID.
     *
     * @param int|null $parentId The parent term ID, or null for a root term.
     */
    public function setParentId(?int $parentId): static
    {
        return $this->set('parent_id', $parentId);
    }

    /**
     * Returns true if this is a root term (no parent).
     */
    public function isRoot(): bool
    {
        $parentId = $this->get('parent_id');

        return $parentId === null || $parentId === 0;
    }

    /**
     * Returns whether this term is published.
     */
    public function isPublished(): bool
    {
        return (bool) ($this->get('status') ?? true);
    }

    /**
     * Sets the published status.
     */
    public function setPublished(bool $published): static
    {
        return $this->set('status', $published);
    }
}
