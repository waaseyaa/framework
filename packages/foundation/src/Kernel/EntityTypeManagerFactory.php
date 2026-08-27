<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as SymfonyContractEventDispatcherInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Concurrency\EntityMutationAuthority;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Validation\DatabaseValidationReadLedger;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * Constructs the EntityTypeManager and its repository factory closure.
 *
 * Extracted from AbstractKernel::bootEntityTypeManager() to isolate the
 * construction logic from the kernel's lifecycle orchestration. All
 * kernel-private dependencies (access-handler resolver, community-scope
 * resolver, account-context attacher) are threaded in as typed callables so
 * the factory remains a pure construction helper with no upward coupling.
 *
 * Kernel exemption surface: this file lives under Kernel/ and may import
 * from any layer — see CLAUDE.md "Kernel/ exemption".
 */
final class EntityTypeManagerFactory
{
    /**
     * Build a fully-wired EntityTypeManager.
     *
     * @param EventDispatcherInterface&SymfonyContractEventDispatcherInterface $dispatcher
     * @param FieldDefinitionRegistryInterface                                  $fieldRegistry
     * @param LoggerInterface                                                   $logger
     * @param callable(): ?\Waaseyaa\Access\EntityAccessHandler                $accessHandlerResolver   Lazy resolver; accessHandler is set after this factory runs.
     * @param callable(EntityTypeInterface): ?\Waaseyaa\EntityStorage\Tenancy\CommunityScope $communityScoreResolver Per-type community-scope resolver.
     * @param callable(object): void                                            $accountContextAttacher  Forward seam (WP01).
     */
    public function build(
        DatabaseInterface $database,
        EventDispatcherInterface&SymfonyContractEventDispatcherInterface $dispatcher,
        FieldDefinitionRegistryInterface $fieldRegistry,
        LoggerInterface $logger,
        callable $accessHandlerResolver,
        callable $communityScoreResolver,
        callable $accountContextAttacher,
        AccountFieldReadScopeInterface $fieldReadScope,
    ): EntityTypeManager {
        // Issue #1643: save-time entity validation is ON by default for every
        // kernel-built repository. One shared stateless EntityValidator is
        // captured by the repository factory closure below. The boot-time env
        // switch WAASEYAA_ENTITY_VALIDATION (0/false/off, case-insensitive)
        // disables the wiring globally; the per-save `validate: false` flag
        // remains the surgical escape hatch. Read once here — not per save,
        // not per repository.
        $raw = getenv('WAASEYAA_ENTITY_VALIDATION');
        $validationEnabled = !\is_string($raw)
            || !\in_array(strtolower($raw), ['0', 'false', 'off'], true);
        $validator = $validationEnabled
            ? EntityValidator::createDefault(new DatabaseValidationReadLedger($database))
            : null;

        $manager = new EntityTypeManager(
            $dispatcher,
            // C-22 WP4: the legacy SqlEntityStorage engine is removed. getStorage()
            // is no longer wired to any first-party persistence engine at boot —
            // it remains a "bring your own EntityStorageInterface" extension seam
            // for entity types that explicitly declare a storageClass.
            null,
            function (string $_entityTypeId, EntityTypeInterface $definition) use ($database, $dispatcher, $fieldRegistry, $logger, $validator, $communityScoreResolver, $accountContextAttacher, $accessHandlerResolver, $fieldReadScope): EntityRepositoryInterface {
                if (!$this->usesFrameworkSqlRuntimeSchema($definition)) {
                    throw new \RuntimeException(\sprintf(
                        'Entity type "%s" declares custom storage "%s"; getRepository() only supports Framework SQL storage. Use getStorage() for the declared backend.',
                        $definition->id(),
                        $definition->getStorageClass(),
                    ));
                }

                $schemaHandler = $this->schemaHandlerFor($definition, $database, $fieldRegistry, $logger);
                $schemaHandler->assertRuntimeSchema();

                $keys = $definition->getKeys();
                $idKey = $keys['id'] ?? 'id';

                $resolver = new SingleConnectionResolver($database);
                $storageBoundary = new StorageBoundary();
                $communityScope = $communityScoreResolver($definition);
                $rawDriver = new SqlStorageDriver(
                    $resolver,
                    $idKey,
                    $communityScope,
                    $fieldRegistry,
                );
                $driver = new SqlStorageDriverV2(
                    $rawDriver,
                    $storageBoundary->driverRowFactory(),
                    $storageBoundary->driverSnapshotReader(),
                );
                $revisionDriver = $definition->isRevisionable()
                    ? new RevisionableStorageDriverV2(
                        new RevisionableStorageDriver($resolver, $definition, communityScope: $communityScope),
                        $storageBoundary->driverRowFactory(),
                        $storageBoundary->driverSnapshotReader(),
                    )
                    : null;

                $repository = new EntityRepository(
                    $definition,
                    $driver,
                    $dispatcher,
                    $revisionDriver,
                    $database,
                    mutationAuthority: new EntityMutationAuthority($database, 'primary'),
                    // Issue #1643: shared default validator (null when the
                    // WAASEYAA_ENTITY_VALIDATION env switch opts out — passing
                    // null matches the constructor default, so disabled boots
                    // construct repositories exactly as before this mission).
                    validator: $validator,
                    fieldRegistry: $fieldRegistry,
                    logger: $logger,
                    // C-22: thread the lazy access-handler resolver so
                    // EntityRepository::getQuery() is fail-closed.
                    accessHandlerResolver: $accessHandlerResolver,
                    storageBoundary: $storageBoundary,
                    fieldReadScope: $fieldReadScope,
                );
                // revision-audit-provenance-01KTWY5V WP01: forward seam — the
                // kernel's shared acting-account context is attached once
                // EntityRepository grows setAccountContext() (WP02 of this
                // mission); a guarded no-op until then. See attachAccountContext().
                $accountContextAttacher($repository);

                return $repository;
            },
            $fieldRegistry,
            $logger,
            // Issue #1376: probe used by EntityTypeManager::addBundleFields()
            // to surface a `[BUNDLE_SUBTABLE_MISSING]` notice once per
            // (entity_type_id, bundle) when the per-bundle subtable is not
            // yet materialized on disk. Resolved name format mirrors
            // SqlSchemaHandler::resolveSubtableName().
            static function (string $entityTypeId, string $bundle) use ($database): bool {
                return $database->schema()->tableExists(
                    SqlSchemaHandler::resolveSubtableName($entityTypeId, $bundle),
                );
            },
            // Bundle registration is definition-only. Repository resolution
            // validates the complete registered shape; schema:sync is the sole
            // materializer. This permits a deployment kernel to load new
            // definitions before its explicit schema transition runs.
            static function (EntityTypeInterface $type): void {},
        );

        return $manager;
    }

    public function assertRegisteredRuntimeSchemas(
        DatabaseInterface $database,
        EntityTypeManager $manager,
        FieldDefinitionRegistryInterface $fieldRegistry,
        LoggerInterface $logger,
    ): void {
        foreach ($manager->getDefinitions() as $definition) {
            if (!$this->usesFrameworkSqlRuntimeSchema($definition)) {
                continue;
            }
            $this->schemaHandlerFor($definition, $database, $fieldRegistry, $logger)->assertRuntimeSchema();
        }
    }

    /**
     * Framework SQL repositories own runtime table readiness. A valid custom
     * {@see EntityStorageInterface} storageClass is a bring-your-own backend
     * and must not be forced to own an SQL table (#2482).
     */
    private function usesFrameworkSqlRuntimeSchema(EntityTypeInterface $definition): bool
    {
        $storageClass = $definition->getStorageClass();
        if ($storageClass === '') {
            return true;
        }
        // Constructor PHPDoc allows any string; this is the runtime contract for
        // malformed storageClass values that are not EntityStorageInterface.
        if (!is_a($storageClass, EntityStorageInterface::class, true)) {
            throw new \RuntimeException(\sprintf(
                'Storage for entity type "%s" must implement %s.',
                $definition->id(),
                EntityStorageInterface::class,
            ));
        }

        return false;
    }

    private function schemaHandlerFor(
        EntityTypeInterface $definition,
        DatabaseInterface $database,
        FieldDefinitionRegistryInterface $fieldRegistry,
        LoggerInterface $logger,
    ): SqlSchemaHandler {
        $backend = $definition->getPrimaryStorageBackend();
        $backend = (\is_string($backend) && $backend !== '')
            ? $backend
            : ReservedBackendIds::SQL_BLOB;
        $entityLevelFields = [];
        if ($backend === ReservedBackendIds::SQL_COLUMN) {
            foreach ($definition->getFieldDefinitions() as $name => $fieldDefinition) {
                if (!$fieldDefinition instanceof FieldDefinition) {
                    continue;
                }
                if ($definition->isTranslatable() && $fieldDefinition->isTranslatable()) {
                    continue;
                }
                $entityLevelFields[$name] = $fieldDefinition;
            }
        }

        return new SqlSchemaHandler(
            entityType: $definition,
            database: $database,
            fieldRegistry: $fieldRegistry,
            logger: $logger,
            primaryBackendId: $backend,
            entityLevelFields: $entityLevelFields,
        );
    }
}
