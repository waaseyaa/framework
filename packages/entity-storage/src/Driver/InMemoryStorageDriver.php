<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

use Waaseyaa\EntityStorage\Tenancy\CommunityScope;
use Waaseyaa\EntityStorage\Tenancy\TenancyViolationException;

/**
 * In-memory storage driver for testing.
 *
 * Stores all data in PHP arrays. No database required.
 * Supports translation data via a separate translations store.
 * @api
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

    /**
     * Per-entity-type auto-increment counter for empty-id writes.
     *
     * @var array<string, int>
     */
    private array $autoIncrement = [];

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

    public function readMultiple(string $entityType, array $ids, ?string $langcode = null): array
    {
        $seen = [];
        $byId = [];
        foreach ($ids as $id) {
            $sid = (string) $id;
            if ($sid === '' || isset($seen[$sid])) {
                continue;
            }
            $seen[$sid] = true;
            $row = $this->read($entityType, $sid, $langcode);
            if ($row !== null) {
                $byId[$sid] = $row;
            }
        }

        return $byId;
    }

    public function write(string $entityType, string $id, array $values): string
    {
        if ($this->communityScope?->isActive()) {
            $active = $this->communityScope->getCommunityId();
            $provided = $values['community_id'] ?? null;
            if (is_string($provided) && $provided !== '' && $provided !== $active) {
                throw TenancyViolationException::conflictingWrite($active, $provided);
            }
            $values['community_id'] = $active;
        }

        if ($id === '') {
            $this->autoIncrement[$entityType] = ($this->autoIncrement[$entityType] ?? 0) + 1;
            $id = (string) $this->autoIncrement[$entityType];
        }

        $this->store[$entityType][$id] = $values;

        return $id;
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
     * Load all stored translation rows for an entity (FR-041 in-memory mirror).
     *
     * Returns each langcode merged onto the base row. The default langcode (when
     * provided) is the first map entry; remaining langcodes follow in ascending
     * lexicographic order. Returns an empty array when no translations were
     * registered via {@see writeTranslation()}.
     */
    public function findTranslations(
        string $entityType,
        string $id,
        ?string $defaultLangcode = null,
    ): array {
        if (!isset($this->store[$entityType][$id])) {
            return [];
        }
        $translations = $this->translations[$entityType][$id] ?? [];
        if ($translations === []) {
            return [];
        }

        $base = $this->store[$entityType][$id];

        if ($this->communityScope?->isActive()
            && ($base['community_id'] ?? null) !== $this->communityScope->getCommunityId()
        ) {
            return [];
        }

        $langcodes = array_keys($translations);
        sort($langcodes);

        // Hoist the default langcode to the front when present.
        if ($defaultLangcode !== null) {
            $filtered = [];
            foreach ($langcodes as $lc) {
                if ($lc !== $defaultLangcode) {
                    $filtered[] = $lc;
                }
            }
            $langcodes = array_key_exists($defaultLangcode, $translations)
                ? array_merge([$defaultLangcode], $filtered)
                : $filtered;
        }

        $rows = [];
        foreach ($langcodes as $lc) {
            $rows[$lc] = array_merge($base, $translations[$lc]);
        }

        return $rows;
    }

    /**
     * Clear all stored data (useful for test teardown).
     */
    public function clear(): void
    {
        $this->store = [];
        $this->translations = [];
        $this->autoIncrement = [];
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
