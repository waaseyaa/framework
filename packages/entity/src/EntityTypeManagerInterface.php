<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * @api
 */
interface EntityTypeManagerInterface
{
    public function getDefinition(string $entityTypeId): EntityTypeInterface;

    /**
     * Resolve the effective field definitions for an entity type, optionally
     * scoped to a bundle.
     *
     * This is the single canonical, registry-aware, bundle-aware read path for
     * "what fields does this thing have". It merges the entity type's
     * class-declared base fields with the field registry's registered core
     * fields and, when a bundle is given, that bundle's registered fields. Every
     * consumer that renders or validates fields (admin schema, GraphQL,
     * validation, JSON:API) reads through this so a content type's true typed
     * field set surfaces consistently and a bundle's distinct fields are never
     * invisible.
     *
     * With no field registry wired, falls back to the entity type's
     * class-declared fields. A null/empty bundle returns base + core only.
     *
     * @return array<string, FieldDefinitionInterface>
     */
    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array;

    /** @throws \DomainException If the entity type ID uses the reserved `core.` namespace. */
    public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void;

    public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void;

    /** @return array<string, EntityTypeInterface> */
    public function getDefinitions(): array;

    public function hasDefinition(string $entityTypeId): bool;

    public function getStorage(string $entityTypeId): Storage\EntityStorageInterface;

    /**
     * Returns the framework {@see EntityRepositoryInterface} for an entity type.
     *
     * Requires a repository factory to be configured on the manager (kernel wiring).
     */
    public function getRepository(string $entityTypeId): EntityRepositoryInterface;
}
