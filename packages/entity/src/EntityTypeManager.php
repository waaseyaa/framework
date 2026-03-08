<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Registry-based entity type manager.
 *
 * Holds entity type definitions and provides access to storage handlers.
 * Entity types are registered explicitly (not discovered via annotations),
 * keeping the system simple and predictable.
 */
class EntityTypeManager implements EntityTypeManagerInterface
{
    /**
     * Registered entity type definitions.
     *
     * @var array<string, EntityTypeInterface>
     */
    private array $definitions = [];

    /**
     * Cached storage handler instances.
     *
     * @var array<string, Storage\EntityStorageInterface>
     */
    private array $storageInstances = [];

    /**
     * @param EventDispatcherInterface $eventDispatcher The event dispatcher for entity lifecycle events.
     * @param \Closure|null $storageFactory A factory callable: fn(EntityTypeInterface): EntityStorageInterface.
     *                                     If null, getStorage() will throw when no storage class is configured.
     */
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?\Closure $storageFactory = null,
    ) {}

    /**
     * Register an entity type definition.
     *
     * The `core.` namespace is reserved for built-in platform types. Attempting
     * to register a type with a `core.*` ID via this method throws NAMESPACE_RESERVED.
     * Use registerCoreEntityType() for platform-level registrations.
     *
     * @throws \DomainException        If the entity type ID uses the reserved `core.` namespace.
     * @throws \InvalidArgumentException If an entity type with the same ID is already registered.
     */
    public function registerEntityType(EntityTypeInterface $type): void
    {
        if (str_starts_with($type->id(), 'core.')) {
            throw new \DomainException(\sprintf(
                '[NAMESPACE_RESERVED] The "core." namespace is reserved for built-in platform types. '
                . 'Entity type "%s" cannot be registered by extensions or tenants. '
                . 'Use a custom namespace prefix instead.',
                $type->id(),
            ));
        }

        $this->persistDefinition($type);
    }

    /**
     * Register a built-in platform entity type, bypassing the `core.` namespace guard.
     *
     * Only kernel boot code and core service providers should call this method.
     *
     * @throws \InvalidArgumentException If an entity type with the same ID is already registered.
     */
    public function registerCoreEntityType(EntityTypeInterface $type): void
    {
        $this->persistDefinition($type);
    }

    private function persistDefinition(EntityTypeInterface $type): void
    {
        if (isset($this->definitions[$type->id()])) {
            throw new \InvalidArgumentException(\sprintf(
                'Entity type "%s" is already registered.',
                $type->id(),
            ));
        }

        $this->definitions[$type->id()] = $type;
    }

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        if (!isset($this->definitions[$entityTypeId])) {
            throw new \InvalidArgumentException(\sprintf(
                'Entity type "%s" is not registered.',
                $entityTypeId,
            ));
        }

        return $this->definitions[$entityTypeId];
    }

    /** @return array<string, EntityTypeInterface> */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    public function hasDefinition(string $entityTypeId): bool
    {
        return isset($this->definitions[$entityTypeId]);
    }

    public function getStorage(string $entityTypeId): Storage\EntityStorageInterface
    {
        if (isset($this->storageInstances[$entityTypeId])) {
            return $this->storageInstances[$entityTypeId];
        }

        $definition = $this->getDefinition($entityTypeId);

        if ($this->storageFactory !== null) {
            $storage = ($this->storageFactory)($definition);
        } else {
            $storageClass = $definition->getStorageClass();

            if ($storageClass === '') {
                throw new \RuntimeException(\sprintf(
                    'No storage class configured for entity type "%s" and no storage factory provided.',
                    $entityTypeId,
                ));
            }

            $storage = new $storageClass();
        }

        if (!$storage instanceof Storage\EntityStorageInterface) {
            throw new \RuntimeException(\sprintf(
                'Storage for entity type "%s" must implement %s.',
                $entityTypeId,
                Storage\EntityStorageInterface::class,
            ));
        }

        $this->storageInstances[$entityTypeId] = $storage;

        return $storage;
    }

    /**
     * Get the event dispatcher.
     */
    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }
}
