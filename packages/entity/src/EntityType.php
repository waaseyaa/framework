<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Value object representing an entity type definition.
 *
 * Entity types are registered with the EntityTypeManager and describe
 * the structure and behavior of a class of entities.
 */
final readonly class EntityType implements EntityTypeInterface
{
    /**
     * @param string $id Machine name of the entity type (e.g. 'node', 'user').
     * @param string $label Human-readable label.
     * @param class-string<EntityInterface> $class The entity class.
     * @param class-string<Storage\EntityStorageInterface> $storageClass The storage handler class.
     * @param array<string, string> $keys Entity keys mapping (id, uuid, label, bundle, revision, langcode).
     * @param bool $revisionable Whether this entity type supports revisions.
     * @param bool $translatable Whether this entity type supports translations.
     * @param string|null $bundleEntityType The entity type ID that provides bundles (e.g. 'node_type' for 'node').
     * @param array<string, mixed> $constraints Validation constraints.
     * @param array<string, array<string, mixed>> $fieldDefinitions Field definitions keyed by field name.
     * @param string|null $description Human-readable description of the entity type.
     */
    public function __construct(
        private string $id,
        private string $label,
        private string $class,
        private string $storageClass = '',
        private array $keys = [],
        private bool $revisionable = false,
        private bool $revisionDefault = false,
        private bool $translatable = false,
        private ?string $bundleEntityType = null,
        private array $constraints = [],
        private array $fieldDefinitions = [],
        private ?string $group = null,
        private ?string $description = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    /** @return class-string<Storage\EntityStorageInterface> */
    public function getStorageClass(): string
    {
        return $this->storageClass;
    }

    /** @return array<string, string> */
    public function getKeys(): array
    {
        return $this->keys;
    }

    public function isRevisionable(): bool
    {
        return $this->revisionable;
    }

    public function getRevisionDefault(): bool
    {
        return $this->revisionDefault;
    }

    public function isTranslatable(): bool
    {
        return $this->translatable;
    }

    public function getBundleEntityType(): ?string
    {
        return $this->bundleEntityType;
    }

    /** @return array<string, mixed> */
    public function getConstraints(): array
    {
        return $this->constraints;
    }

    /** @return array<string, array<string, mixed>> */
    public function getFieldDefinitions(): array
    {
        return $this->fieldDefinitions;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
