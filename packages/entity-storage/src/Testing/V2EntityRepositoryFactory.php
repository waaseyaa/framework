<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Testing;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Event\EntityEventFactoryInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverV2Interface;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriverV2Interface;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\EntityStorageCoordinator;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\I18n\LanguageManagerInterface;

/**
 * Explicit V2 repository composition for tests that inspect a raw fixture backend.
 *
 * @api
 */
final class V2EntityRepositoryFactory
{
    public static function create(
        EntityTypeInterface $entityType,
        EntityStorageDriverInterface|EntityStorageDriverV2Interface $driver,
        EventDispatcherInterface $eventDispatcher,
        RevisionableStorageDriver|RevisionableStorageDriverV2Interface|null $revisionDriver = null,
        ?DatabaseInterface $database = null,
        ?EntityEventFactoryInterface $eventFactory = null,
        ?EntityValidator $validator = null,
        ?EntityStorageCoordinator $coordinator = null,
        ?LanguageManagerInterface $languageManager = null,
        bool $readActiveLanguage = false,
        ?FieldDefinitionRegistryInterface $fieldRegistry = null,
        ?LoggerInterface $logger = null,
        ?EntityAccessHandler $accessHandler = null,
        ?\Closure $accessHandlerResolver = null,
        ?StorageBoundary $storageBoundary = null,
    ): EntityRepository {
        $storageBoundary ??= new StorageBoundary();
        if ($driver instanceof EntityStorageDriverInterface) {
            $driver = new InMemoryStorageDriverV2(
                $driver,
                $storageBoundary->driverRowFactory(),
                $storageBoundary->driverSnapshotReader(),
            );
        }

        return new EntityRepository(
            $entityType,
            $driver,
            $eventDispatcher,
            $revisionDriver,
            $database,
            $eventFactory,
            $validator,
            $coordinator,
            $languageManager,
            $readActiveLanguage,
            $fieldRegistry,
            $logger,
            $accessHandler,
            $accessHandlerResolver,
            $storageBoundary,
        );
    }
}
