<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\ContentModel;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Migration\Exception\ContentModelRegistrationException;

/**
 * Registers a derived {@see ContentModel} into the running framework: it ensures
 * a bundle config entity exists for each content type and declares the type's
 * fields as typed field definitions, so a subsequent content import writes into
 * declared fields instead of an opaque `_data` blob.
 *
 * Generic and source-agnostic. It reaches the bundle config entity type via the
 * destination entity type's declared `bundleEntityType` (e.g. `node_type` for
 * `node`) and constructs it by reflection on the registered class, so it carries
 * no compile-time edge to any Layer-2 content package.
 *
 * Field definitions are registered as per-content-type BUNDLE fields via
 * {@see EntityTypeManager::addBundleFields()}, which also auto-materializes the
 * per-bundle subtable (e.g. `node__page`) with real typed columns. So page and
 * news become genuinely distinct content types with distinct typed fields,
 * persisted in indexable/queryable columns rather than an opaque blob.
 * Registration is idempotent: fields already registered for a bundle are
 * skipped, and the subtable materialization is create-if-missing / add-missing-
 * columns, so the zero-and-re-migrate loop rebuilds cleanly every run.
 *
 * **Blessed as the one supported path for declaring import-derived per-bundle
 * content models (G-026, #1940).** Bound as a singleton by
 * {@see \Waaseyaa\Migration\ServiceProvider} and invoked from
 * {@see \Waaseyaa\Migration\Runner\MigrationRunner} once per CLI invocation,
 * before the first migration runs — see that class's
 * `ensureContentModelsRegistered()`. Later processes call
 * {@see rehydrate()} during provider boot, but that path only restores fields
 * for a bundle config entity already persisted by an import. It never creates
 * a bundle config on first boot. Constructing and calling {@see register()}
 * during `AbstractKernel::boot()`'s schema-sync phase is the sequencing bug
 * that left the pass-1 Sheguiandah build's `SfnWordPressMigrationProvider`
 * silently registering nothing on the first boot after `db:init` — the
 * destination tables did not exist yet. Invoking it at import time (after
 * full kernel boot, including schema sync) removes that hazard by
 * construction; there is no second-boot requirement.
 *
 * **Boundary vs the static app-model path (`docs/specs/work-surface.md`
 * §F2):** this registrar is the RUNTIME/import-derived counterpart to
 * `#[BundleTemplate]`/`#[FieldTemplate]` + `BundleTemplateCompiler`. Static,
 * author-known bundles and fields that ship with an application belong on
 * the attribute path, which is discovered and compiled once at
 * `optimize:manifest` time. Bundles and fields *inferred from a migration
 * source* — unknown until a source reader inspects the actual data — belong
 * here. Both paths terminate at the same substrate
 * (`EntityTypeManager::addBundleFields()` / `FieldDefinitionRegistry`), so
 * this is not a third field-declaration mechanism; it is the same substrate
 * fed from a different, later-arriving source of truth. Do not merge or
 * refactor the two — a compile-time attribute scan cannot see data that
 * only exists once a migration source has been read.
 *
 * Failure semantics (G-026 decision): registration is loud, not best-effort.
 * A structural failure — the content model names a destination entity type
 * that is not registered, the bundle config entity's class or repository
 * cannot save it, the field registry is not configured, or
 * {@see EntityTypeManager::addBundleFields()} rejects a field — raises
 * {@see ContentModelRegistrationException} and aborts registration (and, by
 * extension, the import command that triggered it) before any source record
 * is read. This replaced the earlier notice/error-log-and-continue
 * behaviour, which existed only because this class was (on paper) invocable
 * during kernel boot, where a hard failure would have crashed boot for
 * reasons unrelated to the content model. Full registration now runs only at
 * import time (post-boot), so a loud failure there is strictly better than
 * degrading to "some content silently landed in the `_data` blob instead of
 * a typed column."
 *
 * The only paths that remain silent are genuine no-op cases, not failures:
 * a bundle config entity that already exists (idempotent re-run — the whole
 * point of running this at the top of every import) and a destination
 * entity type that declares no `bundleEntityType` (bundle is a bare string;
 * there is no config container to materialize, by design).
 *
 * @api
 */
final readonly class ContentModelRegistrar
{
    private LoggerInterface $logger;

    public function __construct(
        private EntityTypeManager $entityTypeManager,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Register every content type + field in the model.
     *
     * @throws ContentModelRegistrationException On any structural failure
     *         (see class docblock "Failure semantics"). Aborts registration
     *         of any remaining content types in `$model->types`.
     */
    public function register(ContentModel $model): void
    {
        if ($model->isEmpty()) {
            return;
        }

        foreach ($model->notes as $note) {
            $this->logger->info('[content-model] ' . $note);
        }

        // Taxonomy vocabularies are bundles too. ContentModel has always
        // carried the source vocabulary ids, but the registrar previously
        // ignored them, leaving imported terms with bundle values that had no
        // corresponding taxonomy_vocabulary config entity. Route them through
        // the same generic bundle-config mechanism as every other derived
        // content type so validation and schema presentation agree with the
        // imported data.
        foreach ($model->vocabularies as $vocabulary) {
            $type = new ContentTypeModel(
                entityTypeId: 'taxonomy_term',
                bundle: $vocabulary,
                label: $this->humanizeMachineName($vocabulary),
            );
            $this->ensureBundleConfigEntity($type);
            $this->declareFields($type);
        }

        foreach ($model->types as $type) {
            $this->ensureBundleConfigEntity($type);
            $this->declareFields($type);
        }
    }

    private function humanizeMachineName(string $machineName): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $machineName));
    }

    /**
     * Restore runtime field definitions for content types already persisted by
     * a prior import.
     *
     * The field registry is process-local, while bundle config entities and
     * their subtables survive process boundaries. The persisted bundle config
     * is the guard: a first-install boot before import remains a no-op.
     */
    public function rehydrate(ContentModel $model): void
    {
        if ($model->isEmpty()) {
            return;
        }

        $types = $model->types;
        foreach ($model->vocabularies as $vocabulary) {
            $types[] = new ContentTypeModel(
                entityTypeId: 'taxonomy_term',
                bundle: $vocabulary,
                label: $this->humanizeMachineName($vocabulary),
            );
        }

        foreach ($types as $type) {
            if (!$this->bundleConfigExists($type)) {
                continue;
            }

            $this->declareFields($type);
        }
    }

    private function bundleConfigExists(ContentTypeModel $type): bool
    {
        try {
            $entityType = $this->entityTypeManager->getDefinition($type->entityTypeId);
            $bundleTypeId = $entityType->getBundleEntityType();
            if ($bundleTypeId === null || $bundleTypeId === '') {
                return false;
            }

            return $this->entityTypeManager->getRepository($bundleTypeId)->find($type->bundle) !== null;
        } catch (\Throwable $e) {
            throw new ContentModelRegistrationException(\sprintf(
                '[content-model] could not rehydrate content type "%s" on %s: %s',
                $type->bundle,
                $type->entityTypeId,
                $e->getMessage(),
            ), previous: $e);
        }
    }

    /**
     * Create the bundle config entity (e.g. a node_type row) for the content
     * type, if the destination entity type declares a bundleEntityType and
     * the bundle is not already present. Silent no-op for the two genuine
     * non-failure cases (already exists; no bundleEntityType declared).
     *
     * @throws ContentModelRegistrationException When the destination entity
     *         type is not registered, or the config entity cannot be built
     *         or saved.
     */
    private function ensureBundleConfigEntity(ContentTypeModel $type): void
    {
        try {
            $entityType = $this->entityTypeManager->getDefinition($type->entityTypeId);
        } catch (\Throwable $e) {
            throw new ContentModelRegistrationException(\sprintf(
                '[content-model] destination entity type "%s" is not registered; cannot register content type "%s".',
                $type->entityTypeId,
                $type->bundle,
            ), previous: $e);
        }

        $bundleTypeId = $entityType->getBundleEntityType();
        if ($bundleTypeId === null || $bundleTypeId === '') {
            // Entity type has no bundle config container (bundle is a bare
            // string). Not a failure; nothing to materialize. Field
            // declaration still applies.
            return;
        }

        try {
            $bundleType = $this->entityTypeManager->getDefinition($bundleTypeId);
            $keys = $bundleType->getKeys();
            $idKey = $keys['id'] ?? 'id';
            $labelKey = $keys['label'] ?? $idKey;

            // C-22 WP3: read path now goes through the canonical repository.
            // findBy([]) is the "load all" equivalent of loadMultiple() with no ids.
            $repository = $this->entityTypeManager->getRepository($bundleTypeId);

            // Idempotency: skip if a config entity with this id already exists.
            // Genuine non-failure case — stays silent.
            foreach ($repository->findBy([]) as $existing) {
                if ((string) $existing->id() === $type->bundle) {
                    return;
                }
            }

            $class = $bundleType->getClass();
            $values = [
                $idKey => $type->bundle,
                $labelKey => $type->label,
            ];
            if ($type->description !== '') {
                $values['description'] = $type->description;
            }

            /** @var EntityInterface $configEntity */
            $configEntity = new $class($values);
            if (\method_exists($configEntity, 'enforceIsNew')) {
                $configEntity->enforceIsNew();
            }

            $repository->save($configEntity);

            $this->logger->info(\sprintf(
                '[content-model] registered content type "%s" (%s) as %s "%s".',
                $type->label,
                $type->bundle,
                $bundleTypeId,
                $type->bundle,
            ));
        } catch (\Throwable $e) {
            throw new ContentModelRegistrationException(\sprintf(
                '[content-model] could not materialize %s for content type "%s": %s',
                $bundleTypeId,
                $type->bundle,
                $e->getMessage(),
            ), previous: $e);
        }
    }

    /**
     * Declare the type's distinct fields as per-bundle field definitions, which
     * also auto-materializes the per-bundle subtable with real typed columns.
     * Idempotent: fields already registered for this bundle are skipped.
     *
     * @throws ContentModelRegistrationException When no field registry is
     *         configured on the entity type manager, or
     *         {@see EntityTypeManager::addBundleFields()} rejects a field.
     */
    private function declareFields(ContentTypeModel $type): void
    {
        try {
            $registry = $this->entityTypeManager->getFieldRegistry();
        } catch (\Throwable $e) {
            throw new ContentModelRegistrationException(\sprintf(
                '[content-model] no field registry available; cannot declare fields for content type "%s": %s',
                $type->bundle,
                $e->getMessage(),
            ), previous: $e);
        }

        $existing = $registry->bundleFieldsFor($type->entityTypeId, $type->bundle);

        if ($type->fields === []) {
            if ($this->entityTypeManager->getDefinition($type->entityTypeId)->getBundleEntityType() === null) {
                return;
            }
            if (!in_array($type->bundle, $registry->bundleNamesFor($type->entityTypeId), true)) {
                try {
                    $this->entityTypeManager->addBundleFields($type->entityTypeId, $type->bundle, []);
                } catch (\Throwable $e) {
                    throw new ContentModelRegistrationException(\sprintf(
                        '[content-model] failed to register empty bundle "%s" on %s: %s',
                        $type->bundle,
                        $type->entityTypeId,
                        $e->getMessage(),
                    ), previous: $e);
                }
            }

            return;
        }

        $toAdd = [];
        foreach ($type->fields as $field) {
            $name = $field->getName();
            if (\array_key_exists($name, $existing) || \array_key_exists($name, $toAdd)) {
                continue;
            }
            $toAdd[$name] = $field;
        }

        if ($toAdd === []) {
            return;
        }

        try {
            $this->entityTypeManager->addBundleFields(
                $type->entityTypeId,
                $type->bundle,
                \array_values($toAdd),
            );
        } catch (\Throwable $e) {
            throw new ContentModelRegistrationException(\sprintf(
                '[content-model] failed to register bundle fields for content type "%s" on %s: %s',
                $type->bundle,
                $type->entityTypeId,
                $e->getMessage(),
            ), previous: $e);
        }

        foreach ($toAdd as $name => $field) {
            $this->logger->info(\sprintf(
                '[content-model] declared field "%s" (%s) on content type "%s" (%s).',
                $name,
                $field->getType(),
                $type->bundle,
                $type->entityTypeId,
            ));
        }
    }
}
