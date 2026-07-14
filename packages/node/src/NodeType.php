<?php

declare(strict_types=1);

namespace Waaseyaa\Node;

use Waaseyaa\Entity\ConfigEntityBase;

/**
 * Represents a content type configuration (e.g. "article", "page").
 *
 * NodeType is a configuration entity that defines the properties and
 * behavior of a particular kind of node. It controls defaults like
 * whether new revisions are created and whether authorship info is shown.
 */
final class NodeType extends ConfigEntityBase
{
    protected string $entityTypeId = 'node_type';

    protected array $entityKeys = [
        'id' => 'type',
        'label' => 'name',
    ];

    /**
     * @param array<string, mixed> $values Initial config values.
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see EntityBase::duplicateInstance()}.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
    ) {
        // Ensure defaults for optional properties.
        if (!array_key_exists('description', $values)) {
            $values['description'] = '';
        }
        // Opt-OUT semantics (CW-v1 design decision 1, Drupal parity): a node
        // type defaults to creating a new revision per save; an explicit
        // `new_revision: false` is the per-bundle opt-out.
        if (!array_key_exists('new_revision', $values)) {
            $values['new_revision'] = true;
        }
        if (!array_key_exists('display_submitted', $values)) {
            $values['display_submitted'] = true;
        }

        $entityTypeId = $entityTypeId !== '' ? $entityTypeId : $this->entityTypeId;
        $entityKeys = $entityKeys !== [] ? $entityKeys : $this->entityKeys;

        parent::__construct($values, $entityTypeId, $entityKeys);
    }

    /**
     * Returns the machine name (type) of this node type.
     */
    public function getType(): ?string
    {
        $id = $this->id();

        return $id === null ? null : (string) $id;
    }

    /**
     * Returns the human-readable name.
     */
    public function getName(): string
    {
        return $this->label();
    }

    /**
     * Sets the human-readable name.
     */
    public function setName(string $name): static
    {
        $this->values['name'] = $name;

        return $this;
    }

    /**
     * Returns the description.
     */
    public function getDescription(): string
    {
        return (string) ($this->values['description'] ?? '');
    }

    /**
     * Sets the description.
     */
    public function setDescription(string $description): static
    {
        $this->values['description'] = $description;

        return $this;
    }

    /**
     * Whether new nodes of this type should create revisions by default.
     *
     * Defaults to true (opt-out semantics, CW-v1 design decision 1): the
     * constructor materializes the key, so the `?? true` fallback only
     * covers value bags built outside the constructor.
     */
    public function isNewRevision(): bool
    {
        return (bool) ($this->values['new_revision'] ?? true);
    }

    /**
     * Sets whether new nodes of this type should create revisions by default.
     */
    public function setNewRevision(bool $newRevision): static
    {
        $this->values['new_revision'] = $newRevision;

        return $this;
    }

    /**
     * Whether authorship info (submitted by) should be displayed.
     */
    public function getDisplaySubmitted(): bool
    {
        return (bool) ($this->values['display_submitted'] ?? true);
    }

    /**
     * Sets whether authorship info should be displayed.
     */
    public function setDisplaySubmitted(bool $displaySubmitted): static
    {
        $this->values['display_submitted'] = $displaySubmitted;

        return $this;
    }
}
