<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as SymfonyContractEventDispatcherInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Schema\TranslationSchemaHandler;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * Constructs the EntityTypeManager and its storage/repository factory closures.
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
        $validator = $validationEnabled ? EntityValidator::createDefault() : null;

        return new EntityTypeManager(
            $dispatcher,
            function (EntityTypeInterface $definition) use ($database, $dispatcher, $fieldRegistry, $logger, $accessHandlerResolver): SqlEntityStorage {
                $schemaHandler = new SqlSchemaHandler($definition, $database, $fieldRegistry, null, $logger);
                $schemaHandler->ensureTable();
                // Thread the kernel's access handler lazily so getQuery() is
                // fail-closed (issue #1714). accessHandler is populated by
                // discoverAccessPolicies() during boot; storage is built on first
                // use and cached, so resolve at query time (?? null mirrors the
                // tool-dispatch accessor pattern below).
                return new SqlEntityStorage(
                    $definition,
                    $database,
                    $dispatcher,
                    $fieldRegistry,
                    accessHandlerResolver: $accessHandlerResolver,
                );
            },
            function (string $_entityTypeId, EntityTypeInterface $definition) use ($database, $dispatcher, $fieldRegistry, $logger, $validator, $communityScoreResolver, $accountContextAttacher): EntityRepositoryInterface {
                $schemaHandler = new SqlSchemaHandler($definition, $database, $fieldRegistry, null, $logger);
                $schemaHandler->ensureTable();
                if ($definition->isRevisionable()) {
                    $schemaHandler->ensureRevisionTable();
                }
                if ($definition->isTranslatable()) {
                    // Gate translation-table creation on the storage backend model,
                    // mirroring EntitySchemaSync::syncAll (the CLI db:init path).
                    // sql-blob translatable types keep per-langcode rows IN the base
                    // table (FR-020) and must NOT get a `<entity>_translations`
                    // sibling: materialising an empty one is exactly what forced the
                    // alpha.199 peer-first read fallback to exist. (b2)
                    $backend = $definition->getPrimaryStorageBackend();
                    $backend = (\is_string($backend) && $backend !== '')
                        ? $backend
                        : ReservedBackendIds::SQL_BLOB;
                    if ($backend === ReservedBackendIds::SQL_COLUMN) {
                        new TranslationSchemaHandler($database)->sync($definition);
                    } elseif ($backend !== ReservedBackendIds::SQL_BLOB) {
                        $schemaHandler->ensureTranslationTable();
                    }
                }

                $keys = $definition->getKeys();
                $idKey = $keys['id'] ?? 'id';

                $resolver = new SingleConnectionResolver($database);
                $driver = new SqlStorageDriver(
                    $resolver,
                    $idKey,
                    $communityScoreResolver($definition),
                );
                $revisionDriver = $definition->isRevisionable()
                    ? new RevisionableStorageDriver($resolver, $definition)
                    : null;

                $repository = new EntityRepository(
                    $definition,
                    $driver,
                    $dispatcher,
                    $revisionDriver,
                    $database,
                    // Issue #1643: shared default validator (null when the
                    // WAASEYAA_ENTITY_VALIDATION env switch opts out — passing
                    // null matches the constructor default, so disabled boots
                    // construct repositories exactly as before this mission).
                    validator: $validator,
                    fieldRegistry: $fieldRegistry,
                    logger: $logger,
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
            // Materializer used by EntityTypeManager::addBundleFields() to
            // auto-create/migrate the per-bundle subtable (e.g. node__page) with
            // real typed columns when bundle fields are registered. ensureTable()
            // is idempotent: it creates the base table if missing and the
            // subtable(s) for every registered bundle, so it is safe under the
            // zero-and-re-migrate loop and on re-runs against an existing DB.
            function (EntityTypeInterface $type) use ($database, $fieldRegistry, $logger): void {
                $handler = new SqlSchemaHandler($type, $database, $fieldRegistry, null, $logger);
                $handler->ensureTable();
            },
        );
    }
}
