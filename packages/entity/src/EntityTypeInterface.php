<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Field\FieldDefinitionInterface;

interface EntityTypeInterface
{
    public function id(): string;

    public function getLabel(): string;

    public function getClass(): string;

    /**
     * Storage class for the bring-your-own {@see Storage\EntityStorageInterface} seam.
     *
     * Empty string means Framework SQL repository ownership. Any other string is
     * a runtime class name: valid implementors skip SQL table assertions, and
     * malformed names still fail the existing must-implement contract.
     *
     * @return class-string<Storage\EntityStorageInterface>|string
     */
    public function getStorageClass(): string;

    /** @return array<string, string> Entity keys (id, uuid, label, bundle, revision, langcode, etc.) */
    public function getKeys(): array;

    public function isRevisionable(): bool;

    public function getRevisionDefault(): bool;

    public function isTranslatable(): bool;

    public function getBundleEntityType(): ?string;

    /** @return array<string, mixed> */
    public function getConstraints(): array;

    /**
     * Field definitions keyed by field name.
     *
     * @return array<string, FieldDefinitionInterface>
     */
    public function getFieldDefinitions(): array;

    /**
     * The primary storage backend id for this entity type.
     *
     * `null` means "use the framework default" — `BackendResolver` resolves
     * that to `sql-blob` at runtime. An explicit value (e.g. `'sql-column'`)
     * overrides the framework default for all fields that have not set their
     * own backend via `FieldDefinition::storedIn()`.
     *
     * @api
     */
    public function getPrimaryStorageBackend(): ?string;

    /** @return string|null Admin sidebar group key (e.g. 'content', 'taxonomy'). */
    public function getGroup(): ?string;

    /** @return string|null Human-readable description of the entity type. */
    public function getDescription(): ?string;

    /**
     * Declarative tenancy scope for this entity type.
     *
     * `null` (default) means non-tenant — storage drivers see no community
     * isolation. `['scope' => 'community']` opts the entity type into
     * community-scoped tenancy; the kernel wires a `CommunityScope` into
     * the storage driver when a `CommunityContextInterface` is bound.
     *
     * Replaces the legacy `HasCommunityInterface` marker (deprecated;
     * removal in next minor). See docs/specs/entity-system.md §Tenancy
     * declaration and mission #1257 §C1.
     *
     * @return array{scope: string}|null
     */
    public function getTenancy(): ?array;
}
