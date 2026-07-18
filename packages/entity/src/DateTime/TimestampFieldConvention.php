<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\DateTime;

use InvalidArgumentException;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;

/**
 * Field-definition conventions for {@see \Waaseyaa\EntityStorage\SqlEntityStorage::populateTimestamps()} (#1183).
 */
final class TimestampFieldConvention
{
    /**
     * @param array<string, mixed> $fieldDef
     *
     * @return 'create'|'update'|null Null means do not auto-populate this timestamp field.
     */
    public static function inferAutoPopulate(string $fieldName, array $fieldDef): ?string
    {
        if (array_key_exists('auto_populate', $fieldDef)) {
            $v = $fieldDef['auto_populate'];
            if ($v === false || $v === null) {
                return null;
            }

            if ($v === 'create' || $v === 'update') {
                return $v;
            }

            throw new InvalidArgumentException(sprintf(
                'Invalid auto_populate for timestamp field "%s": expected false, null, "create", or "update", got %s.',
                $fieldName,
                is_scalar($v) ? (string) $v : get_debug_type($v),
            ));
        }

        return match (true) {
            $fieldName === 'created' || $fieldName === 'created_at' => 'create',
            $fieldName === 'changed' || $fieldName === 'updated_at' || $fieldName === 'modified_at' => 'update',
            default => null,
        };
    }

    /**
     * Storage scalar shape when the entity has no {@see \Waaseyaa\Entity\EntityBase::$casts} entry for the field.
     *
     * @param array<string, mixed> $fieldDef
     *
     * @return 'unix'|'iso8601'
     */
    public static function resolveStorageFormat(string $fieldName, array $fieldDef): string
    {
        if (isset($fieldDef['storage_format'])) {
            $f = $fieldDef['storage_format'];
            if ($f === 'unix' || $f === 'iso8601') {
                return $f;
            }

            throw new InvalidArgumentException(sprintf(
                'Invalid storage_format for timestamp field "%s": expected "unix" or "iso8601", got %s.',
                $fieldName,
                is_scalar($f) ? (string) $f : get_debug_type($f),
            ));
        }

        if ($fieldName === 'created' || $fieldName === 'changed') {
            return 'unix';
        }

        if (str_ends_with($fieldName, '_at')) {
            return 'iso8601';
        }

        return 'unix';
    }

    /**
     * Whether the raw storage bag treats the timestamp as unset (populate "create" role may fill).
     */
    public static function isRawTimestampUnset(EntityInterface $entity, string $fieldName): bool
    {
        if ($entity instanceof EntityBase) {
            if (!in_array($fieldName, $entity->fieldNames(), true)) {
                return true;
            }
            $val = $entity->get($fieldName);

            return self::isUnsetValue($val);
        }

        $raw = $entity->toArray();
        if (!array_key_exists($fieldName, $raw)) {
            return true;
        }

        return self::isUnsetValue($raw[$fieldName]);
    }

    private static function isUnsetValue(mixed $val): bool
    {
        if ($val === null || $val === '') {
            return true;
        }

        if (is_int($val) && $val === 0) {
            return true;
        }

        return is_string($val) && $val === '0';
    }
}
