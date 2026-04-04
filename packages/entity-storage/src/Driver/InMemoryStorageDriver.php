<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

use Waaseyaa\EntityStorage\Tenancy\CommunityScope;

/**
 * In-memory storage driver for testing.
 *
 * Stores all data in PHP arrays. No database required.
 * Supports translation data via a separate translations store.
 */
final class InMemoryStorageDriver implements EntityStorageDriverInterface
{
    public function __construct(
        private readonly ?CommunityScope $communityScope = null,
    ) {}

    /**
     * Main storage: entityType => id => values.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $store = [];

    /**
     * Translation storage: entityType => id => langcode => values.
     *
     * @var array<string, array<string, array<string, array<string, mixed>>>>
     */
    private array $translations = [];

    public function read(string $entityType, string $id, ?string $langcode = null): ?array
    {
        if (!isset($this->store[$entityType][$id])) {
            return null;
        }

        $base = $this->store[$entityType][$id];

        if ($this->communityScope?->isActive()) {
            $communityId = $this->communityScope->getCommunityId();
            if (($base['community_id'] ?? null) !== $communityId) {
                return null;
            }
        }

        if ($langcode !== null && isset($this->translations[$entityType][$id][$langcode])) {
            $translation = $this->translations[$entityType][$id][$langcode];
            return array_merge($base, $translation);
        }

        if ($langcode !== null && !empty($this->translations[$entityType][$id])) {
            // Translation requested but not found -- return null to signal missing translation.
            return null;
        }

        return $base;
    }

    public function write(string $entityType, string $id, array $values): void
    {
        $this->store[$entityType][$id] = $values;
    }

    /**
     * Write a translation for an entity.
     *
     * @param string $entityType The entity type machine name.
     * @param string $id The entity ID.
     * @param string $langcode The language code.
     * @param array<string, mixed> $values The translation values.
     */
    public function writeTranslation(string $entityType, string $id, string $langcode, array $values): void
    {
        $this->translations[$entityType][$id][$langcode] = $values;
    }

    /**
     * Delete a specific translation for an entity.
     *
     * @param string $entityType The entity type machine name.
     * @param string $id The entity ID.
     * @param string $langcode The language code to remove.
     */
    public function deleteTranslation(string $entityType, string $id, string $langcode): void
    {
        unset($this->translations[$entityType][$id][$langcode]);
    }

    /**
     * Get available languages for an entity.
     *
     * @param string $entityType The entity type machine name.
     * @param string $id The entity ID.
     * @return string[] Language codes.
     */
    public function getAvailableLanguages(string $entityType, string $id): array
    {
        if (!isset($this->translations[$entityType][$id])) {
            return [];
        }

        return array_keys($this->translations[$entityType][$id]);
    }

    public function remove(string $entityType, string $id): void
    {
        if ($this->communityScope?->isActive()) {
            $row = $this->store[$entityType][$id] ?? null;
            if ($row === null || ($row['community_id'] ?? null) !== $this->communityScope->getCommunityId()) {
                return;
            }
        }

        unset($this->store[$entityType][$id]);
        unset($this->translations[$entityType][$id]);
    }

    public function exists(string $entityType, string $id): bool
    {
        if (!isset($this->store[$entityType][$id])) {
            return false;
        }

        if ($this->communityScope?->isActive()) {
            $communityId = $this->communityScope->getCommunityId();
            return ($this->store[$entityType][$id]['community_id'] ?? null) === $communityId;
        }

        return true;
    }

    public function count(string $entityType, array $criteria = []): int
    {
        if (!isset($this->store[$entityType])) {
            return 0;
        }

        if ($this->communityScope?->isActive()) {
            $criteria['community_id'] = $this->communityScope->getCommunityId();
        }

        if (empty($criteria)) {
            return count($this->store[$entityType]);
        }

        $count = 0;
        foreach ($this->store[$entityType] as $values) {
            if ($this->matchesCriteria($values, $criteria)) {
                $count++;
            }
        }

        return $count;
    }

    public function findBy(
        string $entityType,
        array $criteria = [],
        ?array $orderBy = null,
        ?int $limit = null,
    ): array {
        if (!isset($this->store[$entityType])) {
            return [];
        }

        if ($this->communityScope?->isActive()) {
            $criteria['community_id'] = $this->communityScope->getCommunityId();
        }

        $results = [];
        foreach ($this->store[$entityType] as $values) {
            if ($this->matchesCriteria($values, $criteria)) {
                $results[] = $values;
            }
        }

        if ($orderBy !== null) {
            usort($results, function (array $a, array $b) use ($orderBy): int {
                foreach ($orderBy as $field => $direction) {
                    $aVal = $a[$field] ?? null;
                    $bVal = $b[$field] ?? null;
                    $cmp = $aVal <=> $bVal;
                    if ($cmp !== 0) {
                        return strtoupper($direction) === 'DESC' ? -$cmp : $cmp;
                    }
                }
                return 0;
            });
        }

        if ($limit !== null) {
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }

    /**
     * Clear all stored data (useful for test teardown).
     */
    public function clear(): void
    {
        $this->store = [];
        $this->translations = [];
    }

    /**
     * Check if a row matches the given criteria.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $criteria
     */
    private function matchesCriteria(array $values, array $criteria): bool
    {
        foreach ($criteria as $field => $expected) {
            if (!array_key_exists($field, $values) || $values[$field] !== $expected) {
                return false;
            }
        }

        return true;
    }
}
