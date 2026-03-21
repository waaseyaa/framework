<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\EntityStorage\Connection\ConnectionResolverInterface;

/**
 * SQL driver for revision table I/O.
 *
 * Handles raw read/write against the {entity_table}_revision table.
 * Does not handle entity hydration or event dispatch — that's EntityRepository's job.
 */
final class RevisionableStorageDriver
{
    private readonly string $revisionTable;

    public function __construct(
        private readonly ConnectionResolverInterface $connectionResolver,
        private readonly EntityTypeInterface $entityType,
    ) {
        $this->revisionTable = $this->entityType->id() . '_revision';
    }

    /**
     * Write a new revision row.
     *
     * @param array<string, mixed> $values Field values to snapshot.
     * @return int The new revision ID.
     */
    public function writeRevision(string $entityId, array $values, ?string $log): int
    {
        $db = $this->getDatabase();

        $revisionId = $this->getNextRevisionId($entityId);

        $row = [
            'entity_id' => $entityId,
            'revision_id' => $revisionId,
            'revision_created' => date('Y-m-d H:i:s'),
            'revision_log' => $log,
        ];

        // Add field values, excluding keys that don't belong in revision table.
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        foreach ($values as $key => $value) {
            if ($key === $idKey || $key === 'revision_id' || $key === 'is_default_revision' || $key === 'is_latest_revision') {
                continue;
            }
            $row[$key] = $value;
        }

        $db->insert($this->revisionTable)
            ->fields(array_keys($row))
            ->values($row)
            ->execute();

        return $revisionId;
    }

    /**
     * Update an existing revision row's field values in place.
     *
     * Preserves revision_created and revision_log (immutable metadata).
     *
     * @param array<string, mixed> $values Updated field values.
     */
    public function updateRevision(string $entityId, int $revisionId, array $values): void
    {
        $db = $this->getDatabase();

        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';

        $updateFields = [];
        foreach ($values as $key => $value) {
            if (\in_array($key, [$idKey, 'entity_id', 'revision_id', 'revision_created', 'revision_log', 'is_default_revision', 'is_latest_revision'], true)) {
                continue;
            }
            $updateFields[$key] = $value;
        }

        if ($updateFields === []) {
            return;
        }

        $db->update($this->revisionTable)
            ->fields($updateFields)
            ->condition('entity_id', $entityId)
            ->condition('revision_id', (string) $revisionId)
            ->execute();
    }

    /**
     * Read a specific revision row.
     *
     * @return array<string, mixed>|null
     */
    public function readRevision(string $entityId, int $revisionId): ?array
    {
        $db = $this->getDatabase();

        $result = $db->select($this->revisionTable)
            ->fields($this->revisionTable)
            ->condition('entity_id', $entityId)
            ->condition('revision_id', (string) $revisionId)
            ->execute();

        foreach ($result as $row) {
            return (array) $row;
        }

        return null;
    }

    /**
     * Read multiple revision rows for an entity.
     *
     * @param int[] $revisionIds
     * @return array<int, array<string, mixed>>
     */
    public function readMultipleRevisions(string $entityId, array $revisionIds): array
    {
        $rows = [];
        foreach ($revisionIds as $revId) {
            $row = $this->readRevision($entityId, $revId);
            if ($row !== null) {
                $rows[$revId] = $row;
            }
        }

        return $rows;
    }

    public function getLatestRevisionId(string $entityId): ?int
    {
        $db = $this->getDatabase();

        $result = $db->query(
            'SELECT MAX(revision_id) as max_rev FROM ' . $this->revisionTable . ' WHERE entity_id = ?',
            [$entityId],
        );

        foreach ($result as $row) {
            $row = (array) $row;
            return $row['max_rev'] !== null ? (int) $row['max_rev'] : null;
        }

        return null;
    }

    /**
     * @return int[] Revision IDs in ascending order.
     */
    public function getRevisionIds(string $entityId): array
    {
        $db = $this->getDatabase();

        $result = $db->query(
            'SELECT revision_id FROM ' . $this->revisionTable . ' WHERE entity_id = ? ORDER BY revision_id ASC',
            [$entityId],
        );

        $ids = [];
        foreach ($result as $row) {
            $ids[] = (int) ((array) $row)['revision_id'];
        }

        return $ids;
    }

    public function deleteRevision(string $entityId, int $revisionId): void
    {
        $db = $this->getDatabase();

        // Guard: cannot delete the default revision (invariant #8).
        $baseTable = $this->entityType->id();
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $result = $db->query(
            'SELECT revision_id FROM ' . $baseTable . ' WHERE ' . $idKey . ' = ?',
            [$entityId],
        );
        foreach ($result as $row) {
            $row = (array) $row;
            if ((int) ($row['revision_id'] ?? 0) === $revisionId) {
                throw new \LogicException(
                    "Cannot delete the default revision {$revisionId} for entity {$entityId}. Delete the entity instead."
                );
            }
        }

        $db->delete($this->revisionTable)
            ->condition('entity_id', $entityId)
            ->condition('revision_id', (string) $revisionId)
            ->execute();
    }

    /**
     * Delete all revisions for an entity.
     */
    public function deleteAllRevisions(string $entityId): void
    {
        $db = $this->getDatabase();

        $db->delete($this->revisionTable)
            ->condition('entity_id', $entityId)
            ->execute();
    }

    private function getNextRevisionId(string $entityId): int
    {
        $latest = $this->getLatestRevisionId($entityId);

        return ($latest ?? 0) + 1;
    }

    private function getDatabase(): DatabaseInterface
    {
        return $this->connectionResolver->connection();
    }
}
