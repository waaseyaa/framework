<?php

declare(strict_types=1);

namespace Waaseyaa\Config;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Config\Event\ConfigEvent;
use Waaseyaa\Config\Event\ConfigEvents;

/**
 * Storage decorator that dispatches config events and invalidates factory cache.
 *
 * @internal This class is used by ConfigFactory to wrap storage for editable configs.
 */
final class EventAwareStorage implements StorageInterface
{
    public function __construct(
        private readonly StorageInterface $decorated,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConfigFactory $factory,
    ) {}

    public function exists(string $name): bool
    {
        return $this->decorated->exists($name);
    }

    public function read(string $name): array|false
    {
        return $this->decorated->read($name);
    }

    public function readMultiple(array $names): array
    {
        return $this->decorated->readMultiple($names);
    }

    public function write(string $name, array $data): bool
    {
        $event = new ConfigEvent($name, $data);
        $this->eventDispatcher->dispatch($event, ConfigEvents::PRE_SAVE->value);

        $result = $this->decorated->write($name, $event->getData());

        if ($result) {
            $this->factory->invalidateCache($name);
            $this->eventDispatcher->dispatch(
                new ConfigEvent($name, $event->getData()),
                ConfigEvents::POST_SAVE->value,
            );
        }

        return $result;
    }

    public function delete(string $name): bool
    {
        $this->eventDispatcher->dispatch(
            new ConfigEvent($name),
            ConfigEvents::PRE_DELETE->value,
        );

        $result = $this->decorated->delete($name);

        if ($result) {
            $this->factory->invalidateCache($name);
            $this->eventDispatcher->dispatch(
                new ConfigEvent($name),
                ConfigEvents::POST_DELETE->value,
            );
        }

        return $result;
    }

    public function rename(string $name, string $newName): bool
    {
        return $this->decorated->rename($name, $newName);
    }

    public function listAll(string $prefix = ''): array
    {
        return $this->decorated->listAll($prefix);
    }

    public function deleteAll(string $prefix = ''): bool
    {
        return $this->decorated->deleteAll($prefix);
    }

    public function createCollection(string $collection): static
    {
        return new self(
            $this->decorated->createCollection($collection),
            $this->eventDispatcher,
            $this->factory,
        );
    }

    public function getCollectionName(): string
    {
        return $this->decorated->getCollectionName();
    }

    public function getAllCollectionNames(): array
    {
        return $this->decorated->getAllCollectionNames();
    }
}
