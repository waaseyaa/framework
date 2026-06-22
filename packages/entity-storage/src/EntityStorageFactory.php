<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Event\EntityEventFactoryInterface;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;

/**
 * Factory for creating and caching entity storage instances.
 *
 * Creates SqlEntityStorage instances on demand and caches them
 * by entity type ID so the same storage is reused for repeated
 * requests for the same entity type.
 *
 * When a {@see BackendRegistrar} is supplied, the factory also creates and
 * caches {@see EntityStorageCoordinator} instances for field-level multi-backend
 * fan-out (WP02+). The coordinator is scaffolded here so that WP04 can wire
 * lifecycle events and WP10 can activate field routing without rewriting this class.
 */
final class EntityStorageFactory
{
    /** @var array<string, SqlEntityStorage> */
    private array $storages = [];

    /** @var array<string, EntityStorageCoordinator> */
    private array $coordinators = [];

    /**
     * @param ?\Closure(): ?EntityAccessHandler $accessHandlerResolver Lazy
     *        resolver for the access handler, threaded into each storage so
     *        getQuery() is fail-closed (issue #1714). Resolved at query time so
     *        a handler built after the storage is still seen. Null leaves
     *        getQuery() unfiltered — only valid for system-context callers.
     */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?EntityEventFactoryInterface $eventFactory = null,
        private readonly ?EntityClockInterface $clock = null,
        private readonly ?BackendRegistrar $backendRegistrar = null,
        private readonly ?\Closure $accessHandlerResolver = null,
    ) {}

    /**
     * Returns the storage handler for the given entity type.
     *
     * Creates and caches the storage instance on first access.
     */
    public function getStorage(EntityTypeInterface $entityType): SqlEntityStorage
    {
        $entityTypeId = $entityType->id();

        if (!isset($this->storages[$entityTypeId])) {
            $this->storages[$entityTypeId] = new SqlEntityStorage(
                $entityType,
                $this->database,
                $this->eventDispatcher,
                eventFactory: $this->eventFactory,
                clock: $this->clock,
                accessHandlerResolver: $this->accessHandlerResolver,
            );
        }

        return $this->storages[$entityTypeId];
    }

    /**
     * @api
     *
     * Returns the coordinator for field-level multi-backend fan-out.
     *
     * Returns null when no {@see BackendRegistrar} was supplied (single-backend
     * deployments that have not opted into multi-backend routing).
     *
     * Creates and caches the coordinator instance on first access per entity type.
     * The coordinator is built with a null event-dispatcher slot; WP04 will
     * supply the dispatcher without requiring a constructor change.
     */
    public function getCoordinator(EntityTypeInterface $entityType): ?EntityStorageCoordinator
    {
        if ($this->backendRegistrar === null) {
            return null;
        }

        $entityTypeId = $entityType->id();

        if (!isset($this->coordinators[$entityTypeId])) {
            $resolver = new BackendResolver($this->backendRegistrar);
            $this->coordinators[$entityTypeId] = new EntityStorageCoordinator(
                $resolver,
                $this->backendRegistrar,
                // WP04 event-dispatcher slot — null until lifecycle events are wired.
                null,
            );
        }

        return $this->coordinators[$entityTypeId];
    }
}
