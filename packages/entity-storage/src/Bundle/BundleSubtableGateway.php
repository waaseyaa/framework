<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Bundle;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Reads and writes the per-bundle subtable (e.g. `node__page`) for an entity
 * type, so a content type's distinct typed fields persist in real columns.
 *
 * This is the SINGLE bundle-persistence implementation. Both canonical surfaces
 * use it: {@see \Waaseyaa\EntityStorage\EntityRepository} (the repository write/
 * read path the migration uses) and {@see \Waaseyaa\EntityStorage\SqlEntityStorage}
 * (the getStorage() surface the admin/API uses). There is no second inline copy
 * of partition/upsert/read, so the two paths cannot drift.
 *
 * Column-stored bundle fields are partitioned out of the base row and written to
 * the subtable; `FieldStorage::Data` bundle fields stay in the base `_data` blob
 * (the logged safety net for fields that genuinely have no column).
 *
 * @api
 */
final class BundleSubtableGateway
{
    private readonly string $idKey;
    private readonly LoggerInterface $logger;

    /** @var array<string, bool> Memoized subtable-existence by bundle. */
    private array $existsCache = [];

    /** @var array<string, true> Bundles already logged as missing on save. */
    private array $missingSaveLogged = [];

    /** @var array<string, true> Bundles already logged as missing on load. */
    private array $missingLoadLogged = [];

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly FieldDefinitionRegistryInterface $fieldRegistry,
        private readonly EntityTypeInterface $entityType,
        ?LoggerInterface $logger = null,
    ) {
        $this->idKey = $this->entityType->getKeys()['id'] ?? 'id';
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Whether this entity type has any registered bundle fields (cheap guard so
     * non-bundled types skip all bundle work).
     */
    public function hasBundleFields(): bool
    {
        $bundleKey = $this->entityType->getKeys()['bundle'] ?? null;
        if ($bundleKey === null || $bundleKey === '') {
            return false;
        }

        return $this->fieldRegistry->bundleNamesFor($this->entityType->id()) !== [];
    }

    /**
     * Split entity values into [baseValues, bundleColumnValues, currentBundle].
     *
     * Column-stored bundle fields for the entity's current bundle are pulled into
     * bundleColumnValues (destined for the subtable); `FieldStorage::Data` bundle
     * fields and all other values stay in baseValues (destined for the base row /
     * `_data`). currentBundle is null when there is nothing to partition.
     *
     * A value whose name is a column-stored bundle field of a DIFFERENT bundle
     * (never the current one) is a programming error and throws, so a misrouted
     * write cannot silently corrupt another bundle's subtable shape.
     *
     * @param array<string, mixed> $values
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: ?string}
     */
    public function partition(EntityInterface $entity, array $values): array
    {
        $bundleKey = $this->entityType->getKeys()['bundle'] ?? null;
        if ($bundleKey === null) {
            return [$values, [], null];
        }

        $entityTypeId = $this->entityType->id();
        $registeredBundles = $this->fieldRegistry->bundleNamesFor($entityTypeId);
        if ($registeredBundles === []) {
            return [$values, [], null];
        }

        $currentBundle = $entity->bundle();
        if ($currentBundle === '' || $currentBundle === $entityTypeId) {
            return [$values, [], null];
        }

        $bundleDefs = $this->fieldRegistry->bundleFieldsFor($entityTypeId, $currentBundle);

        // Column-stored bundle field names belonging to OTHER bundles (used to
        // reject misrouted values). A name shared with the current bundle is not
        // "other" — current-bundle membership is checked first below.
        $otherBundleFields = [];
        foreach ($registeredBundles as $bundle) {
            if ($bundle === $currentBundle) {
                continue;
            }
            foreach ($this->fieldRegistry->bundleFieldsFor($entityTypeId, $bundle) as $name => $def) {
                if ($def->getStored() !== FieldStorage::Data) {
                    $otherBundleFields[$name] = $bundle;
                }
            }
        }

        $base = [];
        $bundle = [];
        foreach ($values as $key => $value) {
            if (isset($bundleDefs[$key]) && $bundleDefs[$key]->getStored() !== FieldStorage::Data) {
                $bundle[$key] = $value;
                continue;
            }
            if (isset($otherBundleFields[$key])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Field "%s" belongs to bundle "%s" but entity of type "%s" has bundle "%s".',
                    $key,
                    $otherBundleFields[$key],
                    $entityTypeId,
                    $currentBundle,
                ));
            }
            $base[$key] = $value;
        }

        return [$base, $bundle, $currentBundle];
    }

    public function subtableName(string $bundle): string
    {
        return SqlSchemaHandler::resolveSubtableName($this->entityType->id(), $bundle, $this->entityType->id());
    }

    public function subtableExists(string $bundle): bool
    {
        return $this->existsCache[$bundle]
            ??= $this->database->schema()->tableExists($this->subtableName($bundle));
    }

    /**
     * UPSERT the subtable row for an entity. Portable across SQLite/MySQL/
     * PostgreSQL: probe then UPDATE or INSERT.
     *
     * @param array<string, mixed> $bundleValues
     */
    public function upsert(string $bundle, int|string $id, array $bundleValues): void
    {
        $subtable = $this->subtableName($bundle);

        $existing = $this->database->select($subtable)
            ->fields($subtable, [$this->idKey])
            ->condition($this->idKey, $id)
            ->execute();

        $exists = false;
        foreach ($existing as $_) {
            $exists = true;
            break;
        }

        if ($exists) {
            if ($bundleValues === []) {
                return;
            }
            $this->database->update($subtable)
                ->fields($bundleValues)
                ->condition($this->idKey, $id)
                ->execute();
            return;
        }

        $insertRow = $bundleValues;
        $insertRow[$this->idKey] = $id;
        $this->database->insert($subtable)
            ->fields(\array_keys($insertRow))
            ->values($insertRow)
            ->execute();
    }

    /**
     * Read one entity's subtable bundle-field values, keyed by field name (the id
     * key excluded). Returns [] when there is no row.
     *
     * @return array<string, mixed>
     */
    public function read(string $bundle, int|string $id): array
    {
        $subtable = $this->subtableName($bundle);

        $result = $this->database->select($subtable)
            ->fields($subtable)
            ->condition($this->idKey, $id)
            ->execute();

        foreach ($result as $row) {
            $row = (array) $row;
            unset($row[$this->idKey]);

            return $row;
        }

        return [];
    }

    /**
     * Batch-read many entities' subtable bundle-field values in a single IN
     * query, keyed by stringified id (the id key excluded from each value map).
     *
     * @param list<int|string> $ids
     * @return array<string, array<string, mixed>>
     */
    public function readMany(string $bundle, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $subtable = $this->subtableName($bundle);
        $result = $this->database->select($subtable)
            ->fields($subtable)
            ->condition($this->idKey, $ids, 'IN')
            ->execute();

        $out = [];
        foreach ($result as $row) {
            $row = (array) $row;
            $rowId = $row[$this->idKey] ?? null;
            if ($rowId === null) {
                continue;
            }
            unset($row[$this->idKey]);
            $out[(string) $rowId] = $row;
        }

        return $out;
    }

    /**
     * Log once per bundle that a subtable is missing at save time (its
     * column-bound values are folded into the base `_data` blob by the caller as
     * a never-lossy fallback).
     */
    public function logMissingSubtableOnSave(string $bundle, int $count): void
    {
        if (isset($this->missingSaveLogged[$bundle])) {
            return;
        }
        $this->missingSaveLogged[$bundle] = true;

        $this->logger->warning(\sprintf(
            '[MISSING_BUNDLE_SUBTABLE] Subtable "%s" for entity type "%s" bundle "%s" does not exist at save time; '
            . 'persisting %d bundle-field value(s) to the base "_data" blob as a fallback. Materialize the subtable to '
            . 'store them in typed columns.',
            $this->subtableName($bundle),
            $this->entityType->id(),
            $bundle,
            $count,
        ));
    }

    /**
     * Log once per bundle that a subtable is missing at load time (its
     * bundle-field values are omitted from the loaded entity).
     */
    public function logMissingSubtableOnLoad(string $bundle): void
    {
        if (isset($this->missingLoadLogged[$bundle])) {
            return;
        }
        $this->missingLoadLogged[$bundle] = true;

        $this->logger->notice(\sprintf(
            '[MISSING_BUNDLE_SUBTABLE] Subtable "%s" for entity type "%s" bundle "%s" does not exist at load time; '
            . 'bundle-field values are omitted from loaded entities for this bundle. Materialize the subtable to '
            . 'restore them.',
            $this->subtableName($bundle),
            $this->entityType->id(),
            $bundle,
        ));
    }
}
