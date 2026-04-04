<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\EntityStorage\Connection\ConnectionResolverInterface;
use Waaseyaa\EntityStorage\Tenancy\CommunityScope;

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
            $translationTable = $entityType . '_translations';
            if ($db->schema()->tableExists($translationTable)) {
                return $this->readWithTranslation($db, $entityType, $id, $langcode);
            }
        }

        $result = $query->execute();

        foreach ($result as $row) {
            $row = (array) $row;

            if ($langcode !== null && $db->schema()->fieldExists($entityType, 'langcode')) {
                // If no translation table, filter by langcode in base table.
                if (isset($row['langcode']) && $row['langcode'] !== $langcode) {
                    return null;
                }
            }

            return $row;
        }

        return null;
    }

    public function write(string $entityType, string $id, array $values): void
    {
        $db = $this->getDatabase();

        // Use a scope-unaware existence check: a row with this ID must trigger
        // UPDATE regardless of which community it belongs to, preventing a
        // duplicate INSERT when the active community differs from the stored one.
        $rowExists = $this->rowExistsById($db, $entityType, $id);

        if (!$rowExists) {
            // Insert.
            $db->insert($entityType)
                ->fields(array_keys($values))
                ->values($values)
                ->execute();
        } else {
            // Update: exclude the id from update fields.
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
        }
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
            $query->condition('community_id', $this->communityScope->getCommunityId());
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
            $query->condition('community_id', $this->communityScope->getCommunityId());
        }

        foreach ($criteria as $field => $value) {
            $query->condition($this->resolveField($db, $entityType, $field), $value);
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
            $query->condition('community_id', $this->communityScope->getCommunityId());
        }

        foreach ($criteria as $field => $value) {
            $query->condition($this->resolveField($db, $entityType, $field), $value);
        }

        if ($orderBy !== null) {
            foreach ($orderBy as $field => $direction) {
                $query->orderBy($this->resolveField($db, $entityType, $field), strtoupper($direction));
            }
        }

        if ($limit !== null) {
            $query->range(0, $limit);
        }

        $result = $query->execute();
        $rows = [];

        foreach ($result as $row) {
            $rows[] = (array) $row;
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
            $baseQuery->condition('community_id', $this->communityScope->getCommunityId());
        }

        $baseResult = $baseQuery->execute();

        $base = null;
        foreach ($baseResult as $row) {
            $base = (array) $row;
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
     * Resolve a field name to a SQL expression.
     *
     * Real table columns are returned as-is. Fields stored in the _data
     * JSON blob are wrapped in json_extract().
     */
    private function resolveField(DatabaseInterface $db, string $entityType, string $field): string
    {
        if ($db->schema()->fieldExists($entityType, $field)) {
            return $field;
        }

        return "json_extract(_data, '\$." . $field . "')";
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
}
