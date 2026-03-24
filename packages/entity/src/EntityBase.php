<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Symfony\Component\Uid\Uuid;

/**
 * Abstract base class for all entity types.
 *
 * Provides default implementations of EntityInterface methods.
 * Subclasses must define their entity type ID.
 */
abstract class EntityBase implements EntityInterface
{
    /**
     * The entity type ID (e.g. 'node', 'user').
     *
     * Subclasses must set this to their entity type's machine name.
     */
    protected string $entityTypeId = '';

    /**
     * Internal entity values keyed by field/property name.
     *
     * @var array<string, mixed>
     */
    protected array $values = [];

    /**
     * Whether to force this entity to be treated as new.
     */
    protected bool $enforceIsNew = false;

    /**
     * Entity key mappings from the entity type definition.
     *
     * @var array<string, string>
     */
    protected array $entityKeys = [];

    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param string $entityTypeId The entity type machine name.
     * @param array<string, string> $entityKeys Entity key mappings (id, uuid, label, etc.).
     */
    public function __construct(array $values = [], string $entityTypeId = '', array $entityKeys = [])
    {
        if ($entityTypeId !== '') {
            $this->entityTypeId = $entityTypeId;
        }

        if ($entityKeys !== []) {
            $this->entityKeys = $entityKeys;
        }

        $this->values = $values;

        // Auto-generate UUID only when the entity type defines a uuid key.
        if (isset($this->entityKeys['uuid'])) {
            $uuidKey = $this->entityKeys['uuid'];
            if (!isset($this->values[$uuidKey]) || $this->values[$uuidKey] === '') {
                $this->values[$uuidKey] = Uuid::v4()->toRfc4122();
            }
        }
    }

    public function id(): int|string|null
    {
        $idKey = $this->entityKeys['id'] ?? 'id';

        return $this->values[$idKey] ?? null;
    }

    public function uuid(): string
    {
        $uuidKey = $this->entityKeys['uuid'] ?? 'uuid';

        return $this->values[$uuidKey] ?? '';
    }

    public function label(): string
    {
        $labelKey = $this->entityKeys['label'] ?? 'label';

        return (string) ($this->values[$labelKey] ?? '');
    }

    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }

    public function bundle(): string
    {
        $bundleKey = $this->entityKeys['bundle'] ?? 'bundle';

        // Default bundle is the entity type ID itself when no bundle key exists.
        return (string) ($this->values[$bundleKey] ?? $this->entityTypeId);
    }

    public function isNew(): bool
    {
        return $this->enforceIsNew || $this->id() === null;
    }

    /**
     * Force the entity to be considered new (or not).
     */
    public function enforceIsNew(bool $value = true): static
    {
        $this->enforceIsNew = $value;

        return $this;
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, mixed $value): static
    {
        $this->values[$name] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function language(): string
    {
        $langcodeKey = $this->entityKeys['langcode'] ?? 'langcode';

        return (string) ($this->values[$langcodeKey] ?? 'en');
    }

    /**
     * Called before the entity is persisted. Override for custom logic.
     */
    public function preSave(bool $isNew): void {}

    /**
     * Called after the entity is successfully persisted. Override for custom logic.
     */
    public function postSave(bool $isNew): void {}

    /**
     * Called before the entity is deleted. Override for custom logic.
     */
    public function preDelete(): void {}

    /**
     * Called after the entity is successfully deleted. Override for custom logic.
     */
    public function postDelete(): void {}
}
