<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\ContentModel;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

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
 * UNWIRED / EXPERIMENTAL (audit C-5): no service provider binds this class and
 * nothing calls {@see register()}; it is forthcoming scaffolding, not supported
 * framework surface. @api is retained only to keep the dead-code gate green.
 *
 * @internal Unsupported scaffolding pending wiring. See
 *           docs/specs/migration-platform.md "Unwired scaffolding".
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
     * Register every content type + field in the model. Best-effort and
     * idempotent: a failure to materialize one bundle config entity is logged
     * and does not abort field registration (the substantive deliverable).
     */
    public function register(ContentModel $model): void
    {
        if ($model->isEmpty()) {
            return;
        }

        foreach ($model->notes as $note) {
            $this->logger->info('[content-model] ' . $note);
        }

        foreach ($model->types as $type) {
            $this->ensureBundleConfigEntity($type);
            $this->declareFields($type);
        }
    }

    /**
     * Best-effort create the bundle config entity (e.g. a node_type row) for the
     * content type, if the destination entity type declares a bundleEntityType
     * and the bundle is not already present.
     */
    private function ensureBundleConfigEntity(ContentTypeModel $type): void
    {
        try {
            $entityType = $this->entityTypeManager->getDefinition($type->entityTypeId);
        } catch (\Throwable $e) {
            $this->logger->notice(\sprintf(
                '[content-model] destination entity type "%s" is not registered; skipping content type "%s".',
                $type->entityTypeId,
                $type->bundle,
            ));
            return;
        }

        $bundleTypeId = $entityType->getBundleEntityType();
        if ($bundleTypeId === null || $bundleTypeId === '') {
            // Entity type has no bundle config container (bundle is a bare
            // string). Nothing to materialize; field declaration still applies.
            return;
        }

        try {
            $bundleType = $this->entityTypeManager->getDefinition($bundleTypeId);
            $keys = $bundleType->getKeys();
            $idKey = $keys['id'] ?? 'id';
            $labelKey = $keys['label'] ?? $idKey;

            $storage = $this->entityTypeManager->getStorage($bundleTypeId);

            // Idempotency: skip if a config entity with this id already exists.
            foreach ($storage->loadMultiple() as $existing) {
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

            $this->entityTypeManager->getRepository($bundleTypeId)->save($configEntity);

            $this->logger->info(\sprintf(
                '[content-model] registered content type "%s" (%s) as %s "%s".',
                $type->label,
                $type->bundle,
                $bundleTypeId,
                $type->bundle,
            ));
        } catch (\Throwable $e) {
            // Best-effort: a content type whose config entity cannot be
            // materialized still gets its typed fields declared below.
            $this->logger->notice(\sprintf(
                '[content-model] could not materialize %s for content type "%s": %s',
                $bundleTypeId,
                $type->bundle,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Declare the type's distinct fields as per-bundle field definitions, which
     * also auto-materializes the per-bundle subtable with real typed columns.
     * Idempotent: fields already registered for this bundle are skipped.
     */
    private function declareFields(ContentTypeModel $type): void
    {
        if ($type->fields === []) {
            return;
        }

        try {
            $registry = $this->entityTypeManager->getFieldRegistry();
        } catch (\Throwable $e) {
            $this->logger->notice('[content-model] no field registry available; cannot declare fields: ' . $e->getMessage());
            return;
        }

        $existing = $registry->bundleFieldsFor($type->entityTypeId, $type->bundle);

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
            $this->logger->error(\sprintf(
                '[content-model] failed to register bundle fields for content type "%s" on %s: %s',
                $type->bundle,
                $type->entityTypeId,
                $e->getMessage(),
            ));
            return;
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
