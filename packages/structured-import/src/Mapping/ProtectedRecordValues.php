<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Mapping;

/** @internal */
final class ProtectedRecordValues
{
    /**
     * @param array<array-key, mixed> $fields
     * @return array<string, scalar|null>
     */
    public static function validate(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $field => $value) {
            $field = (string) $field;
            if ($field === '' || strlen($field) > 255 || !mb_check_encoding($field, 'UTF-8')) {
                throw new \InvalidArgumentException('Protected record fields require non-empty bounded UTF-8 string keys.');
            }
            if (array_key_exists($field, $normalized)) {
                throw new \InvalidArgumentException('Protected record field keys must be unique after normalization.');
            }
            if ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException('Protected record field values must be scalar or null.');
            }
            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                throw new \InvalidArgumentException('Protected record string values must be valid UTF-8.');
            }
            if (is_float($value) && !is_finite($value)) {
                throw new \InvalidArgumentException('Protected record float values must be finite.');
            }
            $normalized[$field] = $value;
        }

        return $normalized;
    }

    public static function hashIdentity(string $identity): string
    {
        return hash('sha256', "waaseyaa.structured-import.source-identity.v1\0" . $identity);
    }

    public static function hashRecordId(string $recordId): string
    {
        return hash('sha256', "waaseyaa.structured-import.target-record.v1\0" . $recordId);
    }

    public static function validateOpaqueIdentity(string $identity, string $label): void
    {
        if ($identity === '' || strlen($identity) > 4096 || !mb_check_encoding($identity, 'UTF-8')) {
            throw new \InvalidArgumentException($label . ' must be a non-empty bounded UTF-8 string supplied by the caller.');
        }
    }
}
