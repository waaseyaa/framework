<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * @api
 *
 * The `sql-blob` field storage backend.
 *
 * Stores field values in a JSON `_data` TEXT column alongside named SQL
 * columns for entity-key fields (id, uuid, bundle, langcode). This is the
 * direct refactor of the legacy {@see \Waaseyaa\EntityStorage\SqlEntityStorage}
 * `splitForStorage()` / `mapRowToEntity()` path — observable behaviour is
 * byte-identical post-refactor (FR-007, FR-008).
 *
 * ## Storage contract
 *
 * - Fields whose names correspond to real table columns are stored in those
 *   columns as before.
 * - All other field values are JSON-encoded into the `_data` TEXT column
 *   using the same flags as the legacy path: `JSON_THROW_ON_ERROR` only
 *   (no `JSON_UNESCAPED_UNICODE`, no `JSON_UNESCAPED_SLASHES`).
 * - `json`-typed fields stored in a column are JSON-encoded before write
 *   and decoded on read (matching legacy behaviour).
 * - Fields explicitly marked `FieldStorage::Data` always go to `_data`.
 *
 * ## Query contract (FR-009, FR-010)
 *
 * `supportsQuery()` returns `false` for all field predicates. Equality
 * queries on entity-key columns (`id`, `uuid`, bundle, langcode) are
 * serviced by SqlEntityStorage directly (they live outside `_data`); the
 * backend does not need to support them itself per the spec. Callers
 * MUST raise {@see \Waaseyaa\EntityStorage\Exception\UnsupportedQueryException}
 * at definition-validation time when a backend returns false.
 *
 * ## Read/write semantics
 *
 * `read()` and `write()` operate on a single field at a time (the coordinator
 * dispatch contract). `delete()` clears the entity's entire `_data` blob
 * (sets it to `{}`), leaving column-stored fields to be removed by the
 * coordinator's DELETE SQL statement.
 *
 * @internal Only instantiate via the framework provider; use BackendRegistrar to obtain.
 */
final class SqlBlobBackend implements FieldStorageBackendV2Interface
{
    public const string FINGERPRINT = 'ef98f35497a5cf2e5234b969fe9c7eb9f9e624b3956e635e840b6ef196164c40';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?DatabaseInterface $database,
        private readonly string $entityTableName,
        private readonly string $idKey,
        private readonly string $entityTypeId,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Build the instance the framework backend provider registers (#2160).
     *
     * {@see BackendRegistrar} constructs providers with `new $fqcn()` and keys
     * gateways by backend id alone, so a registered instance is global: it
     * cannot carry the table name, id key or entity type id that this backend's
     * read/write/delete paths need, and there is no seam through which it could
     * receive them.
     *
     * That is sufficient, because the only operation the registrar path
     * actually performs is `supportsQuery()`, which inspects the
     * {@see FieldDefinition} and touches neither the database nor the table.
     * Read, write and delete through a registry-obtained gateway throw rather
     * than silently targeting the wrong table — see requireBinding().
     *
     * Per-entity-type instances are still constructed directly, with a real
     * database, by the storage layer that knows the table.
     */
    public static function forQuerySupport(?LoggerInterface $logger = null): self
    {
        return new self(
            database: null,
            entityTableName: '',
            idKey: '',
            entityTypeId: '',
            logger: $logger,
        );
    }

    public function id(): string
    {
        return ReservedBackendIds::SQL_BLOB;
    }

    public function fingerprint(): string
    {
        return self::FINGERPRINT;
    }

    public function invoke(FieldStorageGatewayRole $gateway, FieldStorageGatewayInput $input): FieldStorageGatewayOutput
    {
        $call = $gateway->unwrap($input, $this);
        $value = match ($call->operation) {
            FieldStorageGatewayOperation::Read => $this->read(
                $call->entity ?? throw new \LogicException('sql-blob read requires an entity.'),
                $call->field ?? throw new \LogicException('sql-blob read requires a field.'),
            ),
            FieldStorageGatewayOperation::Write => $this->write(
                $call->entity ?? throw new \LogicException('sql-blob write requires an entity.'),
                $call->field ?? throw new \LogicException('sql-blob write requires a field.'),
                $call->value,
            ),
            FieldStorageGatewayOperation::Delete => $this->delete(
                $call->entity ?? throw new \LogicException('sql-blob delete requires an entity.'),
            ),
            FieldStorageGatewayOperation::SupportsQuery => $this->supportsQuery(
                $call->field ?? throw new \LogicException('sql-blob query support requires a field.'),
                $call->query ?? throw new \LogicException('sql-blob query support requires a query.'),
            ),
        };

        return $gateway->complete($input, $this, $value);
    }

    /**
     * Read a single field value for an entity.
     *
     * For column-stored fields: fetches directly from the named column.
     * For blob-stored fields: fetches the `_data` JSON and extracts the key.
     *
     * Returns null when the entity does not exist or the field is absent.
     */
    /**
     * @throws \LogicException When invoked on an instance built by
     *   forQuerySupport(), which has no entity table binding (#2160).
     */
    private function requireBinding(string $operation): void
    {
        if ($this->database !== null) {
            return;
        }

        throw new \LogicException(\sprintf(
            'The "sql-blob" backend instance registered with BackendRegistrar supports only '
            . 'query-support checks, so "%s" is unavailable on it: a registry-global instance has no '
            . 'entity table binding. Construct SqlBlobBackend directly with a database, table name and '
            . 'id key for read/write/delete.',
            $operation,
        ));
    }

    private function read(EntityInterface $entity, FieldDefinition $field): mixed
    {
        $this->requireBinding('read');
        $id = $entity->id();
        if ($id === null) {
            return null;
        }

        $fieldName = $field->getName();
        $schema = $this->database->schema();

        if ($this->isDataStored($field) || !$schema->fieldExists($this->entityTableName, $fieldName)) {
            // Field is in the _data blob.
            $result = $this->database->select($this->entityTableName)
                ->fields($this->entityTableName, ['_data'])
                ->condition($this->idKey, $id)
                ->execute();

            foreach ($result as $row) {
                $row = (array) $row;
                $extra = $this->decodeDataBlob($row['_data'] ?? '{}', $id);
                return $extra[$fieldName] ?? null;
            }

            return null;
        }

        // Column-stored field.
        $result = $this->database->select($this->entityTableName)
            ->fields($this->entityTableName, [$fieldName])
            ->condition($this->idKey, $id)
            ->execute();

        foreach ($result as $row) {
            $row = (array) $row;
            $value = $row[$fieldName] ?? null;

            // Decode json-typed column fields on read.
            if ($field->getType() === 'json' && is_string($value)) {
                try {
                    $value = json_decode($value, associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    $this->logger->warning(sprintf(
                        'Corrupt JSON in field "%s" for %s entity %s: %s',
                        $fieldName,
                        $this->entityTypeId,
                        $id,
                        $e->getMessage(),
                    ));
                    $value = null;
                }
            }

            return $value;
        }

        return null;
    }

    /**
     * Write a single field value for an entity.
     *
     * Idempotent: calling write() twice with the same value produces the
     * same stored state as calling it once (UPDATE is applied either way).
     */
    private function write(EntityInterface $entity, FieldDefinition $field, mixed $value): null
    {
        $this->requireBinding('write');
        $id = $entity->id();
        if ($id === null) {
            return null;
        }

        $fieldName = $field->getName();
        $schema = $this->database->schema();

        if ($this->isDataStored($field) || !$schema->fieldExists($this->entityTableName, $fieldName)) {
            // Write to the _data blob: read current blob, set key, re-encode.
            $result = $this->database->select($this->entityTableName)
                ->fields($this->entityTableName, ['_data'])
                ->condition($this->idKey, $id)
                ->execute();

            $extra = [];
            foreach ($result as $row) {
                $row = (array) $row;
                $extra = $this->decodeDataBlob($row['_data'] ?? '{}', $id);
                break;
            }

            $extra[$fieldName] = $value;

            $this->database->update($this->entityTableName)
                ->fields(['_data' => json_encode($extra, \JSON_THROW_ON_ERROR)])
                ->condition($this->idKey, $id)
                ->execute();

            return null;
        }

        // Column-stored field — encode json-typed values.
        if ($field->getType() === 'json' && !is_string($value) && $value !== null) {
            $value = json_encode($value, \JSON_THROW_ON_ERROR);
        }

        $this->database->update($this->entityTableName)
            ->fields([$fieldName => $value])
            ->condition($this->idKey, $id)
            ->execute();

        return null;
    }

    /**
     * Delete all values this backend holds for an entity.
     *
     * Resets the `_data` blob to `{}`. Column-backed fields for this entity
     * are removed by the DELETE SQL issued at the coordinator/storage level.
     */
    private function delete(EntityInterface $entity): null
    {
        $this->requireBinding('delete');
        $id = $entity->id();
        if ($id === null) {
            return null;
        }

        // Only wipe _data if the row still exists (idempotent).
        $exists = false;
        $result = $this->database->select($this->entityTableName)
            ->fields($this->entityTableName, [$this->idKey])
            ->condition($this->idKey, $id)
            ->execute();
        foreach ($result as $_) {
            $exists = true;
            break;
        }

        if ($exists) {
            // Reset to the same empty-array JSON that splitForStorage() produces
            // when $extraData is [] — json_encode([]) === '[]', not '{}'.
            $this->database->update($this->entityTableName)
                ->fields(['_data' => json_encode([], \JSON_THROW_ON_ERROR)])
                ->condition($this->idKey, $id)
                ->execute();
        }

        return null;
    }

    /**
     * {@inheritdoc}
     *
     * sql-blob returns false for all field predicates (FR-009).
     * Entity-key equality queries are handled by SqlEntityStorage directly
     * via real columns and do not go through this method (FR-010).
     */
    private function supportsQuery(FieldDefinition $field, EntityQuery $query): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Determine whether the field must go to _data (not a real column).
     *
     * Mirrors SqlEntityStorage::getDataStoredCoreFieldNames() logic: when
     * FieldStorage::Data is set on the field definition, it routes to the
     * blob even if a legacy column happens to exist.
     */
    private function isDataStored(FieldDefinition $field): bool
    {
        return $field->getStored() === \Waaseyaa\Field\FieldStorage::Data;
    }

    /**
     * Decode the `_data` blob, returning an empty array on corrupt JSON.
     *
     * Mirrors the error-handling in SqlEntityStorage::mapRowToEntity().
     */
    private function decodeDataBlob(string $json, int|string $id): array
    {
        try {
            $decoded = json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException $e) {
            $this->logger->warning(sprintf(
                'Corrupt _data JSON for %s entity %s: %s',
                $this->entityTypeId,
                $id,
                $e->getMessage(),
            ));
            return [];
        }
    }
}
