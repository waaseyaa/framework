<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy;

use Waaseyaa\Entity\ConfigEntityBase;

/**
 * Represents a taxonomy vocabulary (e.g. "tags", "categories").
 *
 * Vocabularies are configuration entities that define groupings of terms.
 * Each vocabulary has a machine name (vid), a human-readable name,
 * an optional description, and a sort weight.
 */
final class Vocabulary extends ConfigEntityBase
{
    protected string $entityTypeId = 'taxonomy_vocabulary';

    protected array $entityKeys = [
        'id' => 'vid',
        'label' => 'name',
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
        // Ensure defaults for optional properties.
        if (!array_key_exists('description', $values)) {
            $values['description'] = '';
        }
        if (!array_key_exists('weight', $values)) {
            $values['weight'] = 0;
        }

        $entityTypeId = $entityTypeId !== '' ? $entityTypeId : $this->entityTypeId;
        $entityKeys = $entityKeys !== [] ? $entityKeys : $this->entityKeys;

        parent::__construct($values, $entityTypeId, $entityKeys);
    }

    /**
     * Returns the vocabulary machine name (the id).
     */
    public function getVid(): string
    {
        return (string) ($this->id() ?? '');
    }

    /**
     * Returns the human-readable vocabulary name.
     */
    public function getName(): string
    {
        return $this->label();
    }

    /**
     * Sets the human-readable vocabulary name.
     */
    public function setName(string $name): static
    {
        $this->set('name', $name);

        return $this;
    }

    /**
     * Returns the vocabulary description.
     */
    public function getDescription(): string
    {
        return (string) ($this->get('description') ?? '');
    }

    /**
     * Sets the vocabulary description.
     */
    public function setDescription(string $description): static
    {
        $this->set('description', $description);

        return $this;
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
        $this->set('weight', $weight);

        return $this;
    }
}
