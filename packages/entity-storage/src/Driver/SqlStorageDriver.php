<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\EntityStorage\Connection\ConnectionResolverInterface;
use Waaseyaa\EntityStorage\ResolvedField;
use Waaseyaa\EntityStorage\Tenancy\CommunityScope;
use Waaseyaa\Field\FieldStorage;

/**
 * SQL-based storage driver.
 *
 * Pure I/O layer: reads and writes rows without entity hydration
 * or event dispatch. Uses the ConnectionResolver to obtain the
 * database connection.
 *
 * The $idKey parameter names the primary key column for the entity type
 * (e.g. 'id', 'nid', 'uid'). Resolved from EntityTypeInterface::getKeys()
 * by the caller, matching the convention used by SqlEntityStorage and
 * SqlEntityQuery.
 */
final class SqlStorageDriver implements EntityStorageDriverInterface
{
    public function __construct(
        private readonly ConnectionResolverInterface $connectionResolver,
        private readonly string $idKey = 'id',
        private readonly ?CommunityScope $communityScope = null,
        // C-22 WP4 fix: mirrors the deleted SqlEntityStorage::$fieldRegistry.
        // Lets splitForWrite() honor an explicit FieldStorage::Data hint over a
        // lingering legacy column (see splitForWrite() docblock, issue #1308).
        private readonly ?FieldDefinitionRegistryInterface $fieldRegistry = null,
    ) {}

    public function read(string $entityType, string $id, ?string $langcode = null): ?array
    {
        $db = $this->getDatabase();

        $query = $db->select($entityType)
            ->fields($entityType)
            ->condition($this->idKey, $id);

        if ($this->communityScope?->isActive()) {
            $query->condition('community_id', $this->communityScope->getCommunityId());
        }

        if ($langcode !== null) {
            $hasLangcodeColumn = $db->schema()->fieldExists($entityType, 'langcode');

            // Two-axis BLOB model: the language is a peer (id, langcode) row in
            // the base table itself. Prefer reading that peer row directly. This
            // takes PRECEDENCE over a `<entity>_translations` sibling (the
            // sql-COLUMN model): that sibling is empty for a blob-translatable
            // entity, so diverting to it would wrongly yield null for a language
            // that exists as a peer row in the base table.
            if ($hasLangcodeColumn) {
                $peerQuery = $db->select($entityType)
                    ->fields($entityType)
                    ->condition($this->idKey, $id)
                    ->condition('langcode', $langcode);

                if ($this->communityScope?->isActive()) {
                    $peerQuery->condition('community_id', $this->communityScope->getCommunityId());
                }

                foreach ($peerQuery->execute() as $row) {
                    return $this->mergeFromRead((array) $row);
                }
            }

            // sql-COLUMN model: the language lives in a `<entity>_translations`
            // sibling (with its own base-row language fallback). Consulted only
            // when the base table has no peer row for this language.
            $translationTable = $entityType . '_translations';
            if ($db->schema()->tableExists($translationTable)) {
                return $this->readWithTranslation($db, $entityType, $id, $langcode);
            }

            // A langcode-aware base table with no matching peer row and no sibling
            // means this language is untranslated: return null rather than falling
            // through to the unfiltered base row (which would be a different
            // language). When the base table is not langcode-aware at all, the
            // langcode does not apply and we fall through to the plain base read.
            if ($hasLangcodeColumn) {
                return null;
            }
        }

        $result = $query->execute();

        foreach ($result as $row) {
            $row = (array) $row;

            return $this->mergeFromRead($row);
        }

        return null;
    }

    public function readMultiple(string $entityType, array $ids, ?string $langcode = null): array
    {
        $db = $this->getDatabase();
        $unique = [];
        foreach ($ids as $id) {
            $sid = (string) $id;
            if ($sid === '') {
                continue;
            }
            $unique[$sid] = true;
        }
        $idList = array_keys($unique);
        if ($idList === []) {
            return [];
        }

        $hasLangcodeColumn = $langcode !== null && $db->schema()->fieldExists($entityType, 'langcode');
        $hasTranslationSibling = $langcode !== null && $db->schema()->tableExists($entityType . '_translations');

        // Only take the language-scoped path when the entity is actually
        // langcode-aware (a base langcode column or a translation sibling).
        // Otherwise the langcode does not apply and we fall through to the plain
        // base read below.
        if ($hasLangcodeColumn || $hasTranslationSibling) {
            $byId = [];

            // Two-axis BLOB model: collect the peer (id, langcode) base rows
            // first. This takes precedence over a `<entity>_translations` sibling
            // (empty for a blob-translatable entity), mirroring read().
            if ($hasLangcodeColumn) {
                $peerQuery = $db->select($entityType)
                    ->fields($entityType)
                    ->condition($this->idKey, $idList, 'IN')
                    ->condition('langcode', $langcode);

                if ($this->communityScope?->isActive()) {
                    $peerQuery->condition('community_id', $this->communityScope->getCommunityId());
                }

                foreach ($peerQuery->execute() as $row) {
                    $row = (array) $row;
                    $pk = $row[$this->idKey] ?? null;
                    if ($pk !== null) {
                        $byId[(string) $pk] = $this->mergeFromRead($row);
                    }
                }
            }

            // sql-COLUMN model: for any ids still missing a peer row, fall back to
            // the `<entity>_translations` sibling (with its own base fallback).
            $missing = array_values(array_filter(
                $idList,
                static fn(int|string $id): bool => !isset($byId[$id]),
            ));
            if ($missing !== [] && $hasTranslationSibling) {
                foreach ($this->readMultipleWithTranslation($db, $entityType, $missing, $langcode) as $key => $row) {
                    $byId[$key] = $row;
                }
            }

            return $byId;
        }

        $query = $db->select($entityType)
            ->fields($entityType)
            ->condition($this->idKey, $idList, 'IN');

        if ($this->communityScope?->isActive()) {
            $query->condition('community_id', $this->communityScope->getCommunityId());
        }

        $byId = [];
        foreach ($query->execute() as $row) {
            $row = (array) $row;
            $pk = $row[$this->idKey] ?? null;
            if ($pk === null) {
                continue;
            }
            $byId[(string) $pk] = $this->mergeFromRead($row);
        }

        return $byId;
    }

    public function write(string $entityType, string $id, array $values): string
    {
        $db = $this->getDatabase();

        // Route values whose column does not exist into the `_data` JSON blob
        // (per SqlSchemaHandler::buildTableSpec the base table only materialises
        // system keys + `_data`; user-defined fields live inside `_data`).
        $values = $this->splitForWrite($db, $entityType, $values);

        // Use a scope-unaware existence check: a row with this ID must trigger
        // UPDATE regardless of which community it belongs to, preventing a
        // duplicate INSERT when the active community differs from the stored one.
        $rowExists = $id !== '' && $this->rowExistsById($db, $entityType, $id);

        if (!$rowExists) {
            // Insert. When $id is empty, strip the id column so the DB assigns
            // an auto-increment value; lastInsertId then reveals the assigned id.
            $insertValues = $values;
            if ($id === '' && array_key_exists($this->idKey, $insertValues) && $insertValues[$this->idKey] === null) {
                unset($insertValues[$this->idKey]);
            }

            $lastInsertId = (string) $db->insert($entityType)
                ->fields(array_keys($insertValues))
                ->values($insertValues)
                ->execute();

            return $id !== '' ? $id : $lastInsertId;
        }

        // Update: exclude the id from update fields. This exclusion is a
        // deliberate contract, not an optimization: row identity is immutable
        // through the storage driver. A divergent id value in $values is
        // silently dropped — the UPDATE is keyed on the existing row's id and
        // never rewrites the id column, so an entity that had a different id
        // set on it cannot move or duplicate a row. Mutating identity requires
        // delete+insert or a migration. Pinned by
        // SqlStorageDriverTest::updateNeverRewritesTheIdColumn (#1646;
        // kitty-specs/live-entity-validation-key-protection-01KTWQT3/contracts/tool-refusal.md).
        $updateFields = [];
        foreach ($values as $key => $value) {
            if ($key === $this->idKey) {
                continue;
            }
            $updateFields[$key] = $value;
        }

        $update = $db->update($entityType)
            ->fields($updateFields)
            ->condition($this->idKey, $id);

        if ($this->communityScope?->isActive()) {
            $update->condition('community_id', $this->communityScope->getCommunityId());
        }

        $update->execute();

        return $id;
    }

    public function remove(string $entityType, string $id): void
    {
        $db = $this->getDatabase();

        // Also remove translations if translation table exists.
        $translationTable = $entityType . '_translations';
        if ($db->schema()->tableExists($translationTable)) {
            $db->delete($translationTable)
                ->condition('entity_id', $id)
                ->execute();
        }

        $delete = $db->delete($entityType)
            ->condition($this->idKey, $id);

        if ($this->communityScope?->isActive()) {
            $delete->condition('community_id', $this->communityScope->getCommunityId());
        }

        $delete->execute();
    }

    public function exists(string $entityType, string $id): bool
    {
        $db = $this->getDatabase();

        $query = $db->select($entityType)
            ->fields($entityType, [$this->idKey])
            ->condition($this->idKey, $id);

        if ($this->communityScope?->isActive()) {
            $query = $query->condition('community_id', $this->communityScope->getCommunityId());
        }

        foreach ($query->execute() as $_row) {
            return true;
        }

        return false;
    }

    public function count(string $entityType, array $criteria = []): int
    {
        $db = $this->getDatabase();

        $query = $db->select($entityType)
            ->countQuery();

        if ($this->communityScope?->isActive()) {
            $query = $query->condition('community_id', $this->communityScope->getCommunityId());
        }

        foreach ($criteria as $field => $value) {
            $resolved = $this->resolveField($db, $entityType, $field);
            $query = $resolved->isExpression()
                ? $query->whereRaw($resolved->sql() . ' = ?', [$value])
                : $query->condition($resolved->sql(), $value);
        }

        $result = $query->execute();

        foreach ($result as $row) {
            $row = (array) $row;
            return (int) ($row['count'] ?? 0);
        }

        return 0;
    }

    public function findBy(
        string $entityType,
        array $criteria = [],
        ?array $orderBy = null,
        ?int $limit = null,
    ): array {
        $db = $this->getDatabase();

        $query = $db->select($entityType)
            ->fields($entityType);

        if ($this->communityScope?->isActive()) {
            $query = $query->condition('community_id', $this->communityScope->getCommunityId());
        }

        foreach ($criteria as $field => $value) {
            $resolved = $this->resolveField($db, $entityType, $field);
            $query = $resolved->isExpression()
                ? $query->whereRaw($resolved->sql() . ' = ?', [$value])
                : $query->condition($resolved->sql(), $value);
        }

        if ($orderBy !== null) {
            foreach ($orderBy as $field => $direction) {
                $resolved = $this->resolveField($db, $entityType, $field);
                $query = $resolved->isExpression()
                    ? $query->orderByRaw($resolved->sql(), strtoupper($direction))
                    : $query->orderBy($resolved->sql(), strtoupper($direction));
            }
        }

        if ($limit !== null) {
            $query = $query->range(0, $limit);
        }

        $result = $query->execute();
        $rows = [];

        foreach ($result as $row) {
            $rows[] = $this->mergeFromRead((array) $row);
        }

        return $rows;
    }

    /**
     * Load every translation row for an entity in a single query (FR-041/FR-042).
     *
     * Detection rules:
     *   - sql-column layout: companion table `<entityType>__translation` exists
     *     → single INNER JOIN of primary + translation, ordered default-first via CASE.
     *   - sql-blob layout: primary table carries a `default_langcode` column and
     *     a `(entity_id, langcode)` composite PK is implied by repeated rows
     *     → single SELECT against the primary table ordered default-first via CASE.
     *   - Neither shape detected → empty array (the entity type is not
     *     translation-aware in storage; caller surfaces []).
     *
     * @return array<string, array<string, mixed>> langcode → row map.
     */
    public function findTranslations(
        string $entityType,
        string $id,
        ?string $defaultLangcode = null,
    ): array {
        $db = $this->getDatabase();
        $schema = $db->schema();

        // sql-column layout: companion table `<entityType>__translation` (WP05).
        $sqlColumnTable = $entityType . '__translation';
        if ($schema->tableExists($sqlColumnTable)) {
            return $this->findTranslationsSqlColumn($db, $entityType, $sqlColumnTable, $id, $defaultLangcode);
        }

        // sql-blob layout: PK widened on the primary table; `langcode` column
        // present and `default_langcode` column carried per row (WP04).
        if ($schema->fieldExists($entityType, 'langcode')
            && $schema->fieldExists($entityType, 'default_langcode')
        ) {
            return $this->findTranslationsSqlBlob($db, $entityType, $id, $defaultLangcode);
        }

        // No translation-aware storage shape — caller surfaces [].
        return [];
    }

    /**
     * sql-column path: single INNER JOIN of primary + translation table.
     *
     * @return array<string, array<string, mixed>>
     */
    private function findTranslationsSqlColumn(
        DatabaseInterface $db,
        string $primaryTable,
        string $translationTable,
        string $id,
        ?string $defaultLangcode,
    ): array {
        $quote = $db instanceof DBALDatabase
            ? static fn(string $col): string => $db->quoteIdentifier($col)
            : static fn(string $col): string => '"' . str_replace('"', '""', $col) . '"';

        // ORDER BY: default-first when we know the default langcode (from the
        // entity), otherwise rely on the row's own `default_langcode` column
        // matched against `t.langcode`. We pass $defaultLangcode positionally
        // so the CASE works even when the primary row's default column is
        // null (e.g. legacy data).
        $orderClause = $defaultLangcode !== null
            ? sprintf(
                'CASE WHEN t.%s = ? THEN 0 ELSE 1 END, t.%s',
                $quote('langcode'),
                $quote('langcode'),
            )
            : sprintf(
                'CASE WHEN t.%s = pri.%s THEN 0 ELSE 1 END, t.%s',
                $quote('langcode'),
                $quote('default_langcode'),
                $quote('langcode'),
            );

        $sql = sprintf(
            'SELECT pri.*, t.* FROM %s pri INNER JOIN %s t ON t.%s = pri.%s WHERE pri.%s = ? ORDER BY %s',
            $quote($primaryTable),
            $quote($translationTable),
            $quote('entity_id'),
            $quote($this->idKey),
            $quote($this->idKey),
            $orderClause,
        );

        $args = $defaultLangcode !== null ? [$id, $defaultLangcode] : [$id];

        $rows = [];
        foreach ($db->query($sql, $args) as $row) {
            $row = (array) $row;
            $lc = isset($row['langcode']) ? (string) $row['langcode'] : null;
            if ($lc === null || $lc === '') {
                continue;
            }
            unset($row['entity_id']);
            $rows[$lc] = $this->mergeFromRead($row);
        }

        return $rows;
    }

    /**
     * sql-blob path: single SELECT FROM <table> WHERE <idKey> = ?.
     *
     * @return array<string, array<string, mixed>>
     */
    private function findTranslationsSqlBlob(
        DatabaseInterface $db,
        string $entityType,
        string $id,
        ?string $defaultLangcode,
    ): array {
        $quote = $db instanceof DBALDatabase
            ? static fn(string $col): string => $db->quoteIdentifier($col)
            : static fn(string $col): string => '"' . str_replace('"', '""', $col) . '"';

        $orderClause = $defaultLangcode !== null
            ? sprintf(
                'CASE WHEN %s = ? THEN 0 ELSE 1 END, %s',
                $quote('langcode'),
                $quote('langcode'),
            )
            : sprintf(
                'CASE WHEN %s = %s THEN 0 ELSE 1 END, %s',
                $quote('langcode'),
                $quote('default_langcode'),
                $quote('langcode'),
            );

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = ? ORDER BY %s',
            $quote($entityType),
            $quote($this->idKey),
            $orderClause,
        );

        $args = $defaultLangcode !== null ? [$id, $defaultLangcode] : [$id];

        $rows = [];
        foreach ($db->query($sql, $args) as $row) {
            $row = (array) $row;
            $lc = isset($row['langcode']) ? (string) $row['langcode'] : null;
            if ($lc === null || $lc === '') {
                continue;
            }
            $rows[$lc] = $this->mergeFromRead($row);
        }

        return $rows;
    }

    /**
     * Read a row with translation data merged from the translation table.
     *
     * @return array<string, mixed>|null
     */
    private function readWithTranslation(
        DatabaseInterface $db,
        string $entityType,
        string $id,
        string $langcode,
    ): ?array {
        // Load base entity first.
        $baseQuery = $db->select($entityType)
            ->fields($entityType)
            ->condition($this->idKey, $id);

        if ($this->communityScope?->isActive()) {
            $baseQuery = $baseQuery->condition('community_id', $this->communityScope->getCommunityId());
        }

        $baseResult = $baseQuery->execute();

        $base = null;
        foreach ($baseResult as $row) {
            $base = $this->mergeFromRead((array) $row);
            break;
        }

        if ($base === null) {
            return null;
        }

        // Load translation row.
        $translationTable = $entityType . '_translations';
        $transResult = $db->select($translationTable)
            ->fields($translationTable)
            ->condition('entity_id', $id)
            ->condition('langcode', $langcode)
            ->execute();

        $translation = null;
        foreach ($transResult as $row) {
            $translation = (array) $row;
            break;
        }

        if ($translation === null) {
            return null;
        }

        // Merge: translation values override base values.
        // Remove join keys from translation before merge.
        unset($translation['entity_id']);
        $merged = array_merge($base, $translation);

        return $merged;
    }

    /**
     * @param list<string> $idList
     * @return array<string, array<string, mixed>>
     */
    private function readMultipleWithTranslation(
        DatabaseInterface $db,
        string $entityType,
        array $idList,
        string $langcode,
    ): array {
        $baseQuery = $db->select($entityType)
            ->fields($entityType)
            ->condition($this->idKey, $idList, 'IN');

        if ($this->communityScope?->isActive()) {
            $baseQuery = $baseQuery->condition('community_id', $this->communityScope->getCommunityId());
        }

        $bases = [];
        foreach ($baseQuery->execute() as $row) {
            $row = $this->mergeFromRead((array) $row);
            $pk = $row[$this->idKey] ?? null;
            if ($pk !== null) {
                $bases[(string) $pk] = $row;
            }
        }

        if ($bases === []) {
            return [];
        }

        $translationTable = $entityType . '_translations';
        $transQuery = $db->select($translationTable)
            ->fields($translationTable)
            ->condition('entity_id', $idList, 'IN')
            ->condition('langcode', $langcode);

        $translations = [];
        foreach ($transQuery->execute() as $row) {
            $row = (array) $row;
            $entityId = $row['entity_id'] ?? null;
            if ($entityId !== null) {
                $translations[(string) $entityId] = $row;
            }
        }

        $merged = [];
        foreach ($bases as $sid => $base) {
            if (!isset($translations[$sid])) {
                continue;
            }
            $translation = $translations[$sid];
            unset($translation['entity_id']);
            $merged[$sid] = array_merge($base, $translation);
        }

        return $merged;
    }

    /**
     * Resolve a field name to its SQL form (WP6).
     *
     * Real table columns become a bare {@see ResolvedField::identifier()}
     * (auto-quoted downstream by condition()/orderBy()). Fields stored in the
     * _data JSON blob become a json_extract {@see ResolvedField::expression()}
     * routed through whereRaw()/orderByRaw() (emitted verbatim).
     */
    private function resolveField(DatabaseInterface $db, string $entityType, string $field): ResolvedField
    {
        if ($db->schema()->fieldExists($entityType, $field)) {
            return ResolvedField::identifier($field);
        }

        return ResolvedField::expression("json_extract(_data, '\$." . $field . "')", isJsonExtract: true);
    }

    /**
     * Scope-unaware existence check by primary key only.
     *
     * Used by write() to detect INSERT vs UPDATE without letting community
     * scope cause a false "not found" that would produce a duplicate INSERT.
     */
    private function rowExistsById(DatabaseInterface $db, string $entityType, string $id): bool
    {
        $result = $db->select($entityType)
            ->fields($entityType, [$this->idKey])
            ->condition($this->idKey, $id)
            ->execute();

        foreach ($result as $_row) {
            return true;
        }

        return false;
    }

    private function getDatabase(): DatabaseInterface
    {
        return $this->connectionResolver->connection();
    }

    /**
     * Route entity values into existing columns vs the `_data` JSON blob.
     *
     * SqlSchemaHandler::buildTableSpec() materialises only system keys
     * (id/uuid/bundle/label/langcode + revision pointer) and a `_data` text
     * column. Any other value the entity carries (declarative `#[Field]`
     * attributes that don't have a dedicated column, ad-hoc set() calls)
     * lives inside `_data` as JSON. The legacy SqlEntityStorage path does
     * this split internally; the repository → driver path goes through here
     * so the same convention holds for both.
     *
     * A field explicitly registered with a `FieldStorage::Data` hint (see
     * {@see dataStoredCoreFieldNames()}) always routes to `_data`, even when a
     * legacy column of the same name still exists in the schema (issue #1308) —
     * the registry's hint is authoritative over residual schema state.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function splitForWrite(DatabaseInterface $db, string $entityType, array $values): array
    {
        $schema = $db->schema();
        $hasDataColumn = $schema->fieldExists($entityType, '_data');
        $dataStoredFields = $this->dataStoredCoreFieldNames($entityType);

        $dbValues = [];
        $extraData = [];

        foreach ($values as $key => $value) {
            if ($key === '_data') {
                // Caller supplied a pre-built `_data` payload — fold it into
                // our extras bucket so any column-routed values still land in
                // their own columns.
                if (is_string($value) && $value !== '') {
                    try {
                        $decoded = json_decode($value, associative: true, flags: \JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $extraData = $decoded + $extraData;
                        }
                    } catch (\JsonException) {
                        // Ignore malformed pre-built blobs; rebuild from scratch.
                    }
                } elseif (is_array($value)) {
                    $extraData = $value + $extraData;
                }
                continue;
            }

            if (isset($dataStoredFields[$key])) {
                $extraData[$key] = $value;
            } elseif ($schema->fieldExists($entityType, $key)) {
                $dbValues[$key] = $value;
            } else {
                $extraData[$key] = $value;
            }
        }

        if ($hasDataColumn) {
            $dbValues['_data'] = json_encode($extraData, \JSON_THROW_ON_ERROR);
        }

        return $dbValues;
    }

    /**
     * Core field names whose registered storage hint is `FieldStorage::Data`.
     * Mirrors the deleted `SqlEntityStorage::getDataStoredCoreFieldNames()` so
     * `splitForWrite()` keeps `_data`-routed fields out of base columns even
     * when a legacy column happens to still exist.
     *
     * @return array<string, true>
     */
    private function dataStoredCoreFieldNames(string $entityType): array
    {
        if ($this->fieldRegistry === null) {
            return [];
        }

        $names = [];
        foreach ($this->fieldRegistry->coreFieldsFor($entityType) as $name => $definition) {
            if ($definition->getStored() === FieldStorage::Data) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * Decode the `_data` JSON blob and merge its keys back onto the row.
     *
     * Inverse of {@see self::splitForWrite()}. Applied at every read boundary
     * so consumers (EntityRepository hydration, findBy result iteration) see
     * a flat value map without having to know about the `_data` convention.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mergeFromRead(array $row): array
    {
        if (!array_key_exists('_data', $row)) {
            return $row;
        }

        $raw = $row['_data'];
        unset($row['_data']);

        if (!is_string($raw) || $raw === '') {
            return $row;
        }

        try {
            $extra = json_decode($raw, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $row;
        }

        if (!is_array($extra)) {
            return $row;
        }

        // Column values win over `_data` to handle legacy rows where the
        // same key appears in both (transient migration state).
        return $row + $extra;
    }
}
