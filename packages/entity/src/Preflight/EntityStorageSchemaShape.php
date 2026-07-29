<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Preflight;

/**
 * Canonical entity-storage schema shape used by the field-access preflight
 * fingerprint — shared by the CLI inventory scanner (artifact side) and the
 * kernel activation guard (boot side) so both hash the exact same surface.
 *
 * The fingerprint deliberately covers ONLY tables that belong to registered
 * entity storage (the base table plus `<type>__*` revision / translation /
 * bundle subtables): that is the universe the field-read inventory is derived
 * from. First-party runtime services materialize their own non-entity tables
 * lazily on first use (rate limiting, publishing idempotency, broadcast
 * retention, OIDC token stores, the FTS5 search index, …) — fingerprinting
 * every physical table meant the first production request after deploy
 * changed the fingerprint and the NEXT request failed boot as stale (#2143).
 * Non-entity tables carry no field-read surface, so they are excluded here;
 * a new or reshaped entity table still changes the fingerprint and correctly
 * invalidates the artifact.
 *
 * @internal shared preflight contract between waaseyaa/cli and the kernel
 */
final readonly class EntityStorageSchemaShape
{
    /** @param list<string> $entityTypeIds */
    public static function belongsToEntityStorage(string $table, array $entityTypeIds): bool
    {
        foreach ($entityTypeIds as $entityTypeId) {
            if ($entityTypeId === '') {
                continue;
            }
            if ($table === $entityTypeId || str_starts_with($table, $entityTypeId . '__')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<string>> $shape  table => column names
     * @param list<string> $entityTypeIds
     * @return array<string, list<string>>
     */
    public static function filter(array $shape, array $entityTypeIds): array
    {
        $filtered = [];
        foreach ($shape as $table => $columns) {
            if (self::belongsToEntityStorage($table, $entityTypeIds)) {
                sort($columns);
                $filtered[$table] = $columns;
            }
        }
        ksort($filtered);

        return $filtered;
    }

    /** @param array<string, list<string>> $shape */
    public static function fingerprint(array $shape): string
    {
        ksort($shape);
        foreach ($shape as &$columns) {
            sort($columns);
        }
        unset($columns);

        return hash('sha256', json_encode($shape, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
