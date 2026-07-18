<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendGateway;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Exception\UnknownBackendException;
use Waaseyaa\Field\FieldDefinition;

/**
 * @api
 *
 * Resolves the storage backend for a given field definition within an entity type.
 *
 * ## Precedence (highest to lowest)
 *
 * 1. {@see FieldDefinition::getBackendId()} — explicit per-field override via `storedIn()`.
 * 2. `EntityType::$primaryStorageBackend` — per-entity-type default (added in WP07;
 *    absent until then, treated as the framework default).
 * 3. Framework default: `sql-blob`.
 *
 * ## Resolution failure
 *
 * When the resolved id is not registered, throws {@see UnknownBackendException}
 * with a precise context message. Silent fallback is explicitly forbidden (spec §5.4,
 * research D6, WP02 carry-forward constraint #2 / #9).
 */
final class BackendResolver
{
    public function __construct(
        private readonly BackendRegistrar $registrar,
    ) {}

    /**
     * Resolve the backend responsible for storing the given field of the given entity type.
     *
     * @throws UnknownBackendException When the resolved backend id is not registered.
     */
    public function resolve(EntityTypeInterface $entityType, FieldDefinition $field): FieldStorageBackendGateway
    {
        $backendId = $this->resolveId($entityType, $field);

        $backend = $this->registrar->gateway($backendId);

        if ($backend === null) {
            throw new UnknownBackendException(
                $backendId,
                sprintf(
                    'field "%s" on entity type "%s"',
                    $field->getName(),
                    $entityType->id(),
                ),
            );
        }

        return $backend;
    }

    /**
     * Resolve the backend id for a field without fetching the backend instance.
     *
     * Useful for grouping fields by backend id before dispatching.
     */
    public function resolveId(EntityTypeInterface $entityType, FieldDefinition $field): string
    {
        // Tier 1: explicit per-field override.
        $fieldBackendId = $field->getBackendId();
        if ($fieldBackendId !== null && $fieldBackendId !== '') {
            return $fieldBackendId;
        }

        // Tier 2: per-entity-type primary backend (WP07 added getPrimaryStorageBackend()
        // to EntityTypeInterface; direct call replaces the pre-WP07 reflection guard).
        $primary = $entityType->getPrimaryStorageBackend();
        if ($primary !== null && $primary !== '') {
            return $primary;
        }

        // Tier 3: framework default.
        return ReservedBackendIds::SQL_BLOB;
    }
}
