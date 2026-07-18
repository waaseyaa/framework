<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Entity\Cast\FromArrayEntityValueInterface;

/**
 * Helpers for read paths that need cast-aware field values (#1181).
 *
 * {@see EntityInterface::toArray()} and storage remain storage-canonical; use this for API/SSR/GraphQL
 * and other presentation logic so {@see EntityBase::$casts} apply. Do not use for persistence snapshots.
 */
final class EntityValues
{
    /**
     * Selected stored fields with values from the guarded {@see EntityInterface::get()} path.
     *
     * Framework entities enumerate without exporting a value bag. The `toArray()` fallback exists
     * only for third-party EntityInterface implementations that do not expose fieldNames().
     *
     * @param list<string>|null $fieldNames Null selects every ordinary-readable stored field name.
     * @return array<string, mixed>
     */
    public static function toCastAwareMap(EntityInterface $entity, ?array $fieldNames = null): array
    {
        $map = [];
        foreach ($fieldNames ?? self::ordinaryFieldNames($entity) as $fieldName) {
            $map[$fieldName] = $entity->get($fieldName);
        }

        return $map;
    }

    /**
     * Non-value-bearing field enumeration for framework entities.
     *
     * @return list<string>
     */
    public static function fieldNames(EntityInterface $entity): array
    {
        if ($entity instanceof EntityBase) {
            return $entity->fieldNames();
        }

        $names = array_map('strval', array_keys($entity->toArray()));
        sort($names);

        return $names;
    }

    /**
     * Field names eligible for ordinary guarded projection. Internal values require an audited reader.
     *
     * @return list<string>
     */
    public static function ordinaryFieldNames(EntityInterface $entity): array
    {
        $names = self::fieldNames($entity);
        if (!$entity instanceof EntityBase) {
            return $names;
        }

        return array_values(array_filter(
            $names,
            static fn(string $field): bool => $entity->fieldReadLevel($field) !== FieldReadLevel::Internal,
        ));
    }

    /**
     * Normalize status-like flags to 0/1 for strict comparisons (e.g. relationship published filters).
     */
    public static function statusToInt(mixed $status): int
    {
        if ($status instanceof \BackedEnum) {
            return self::statusToInt($status->value);
        }

        if ($status === true || $status === 1) {
            return 1;
        }

        if ($status === false || $status === 0 || $status === null) {
            return 0;
        }

        if (\is_string($status)) {
            $t = strtolower(trim($status));
            if (\in_array($t, ['1', 'true', 'published', 'yes'], true)) {
                return 1;
            }

            if (\in_array($t, ['0', 'false', 'no', ''], true)) {
                return 0;
            }
        }

        if (is_numeric($status)) {
            return ((int) $status) === 1 ? 1 : 0;
        }

        return 0;
    }

    /**
     * Cast-aware field map with each value normalized for {@see \json_encode()} (ST-10, #1181).
     *
     * Use for embedding payloads, diagnostics, and other JSON sinks that must reflect
     * {@see EntityBase::$casts} (enums, datetimes, nested structures) without reading raw
     * {@see EntityInterface::toArray()} storage scalars.
     *
     * @return array<string, mixed>
     */
    public static function toJsonReadyMap(EntityInterface $entity): array
    {
        $out = [];
        foreach (self::toCastAwareMap($entity) as $key => $value) {
            $out[$key] = self::normalizeValueForJson($value);
        }

        return $out;
    }

    /**
     * Recursively normalize a cast-aware value for JSON encoding (shared with JSON:API attributes).
     */
    public static function normalizeValueForJson(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if ($value instanceof \JsonSerializable) {
            return self::normalizeValueForJson($value->jsonSerialize());
        }

        if ($value instanceof FromArrayEntityValueInterface) {
            return self::normalizeValueForJson($value->toArray());
        }

        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalizeValueForJson($item);
            }

            return $normalized;
        }

        return $value;
    }
}
