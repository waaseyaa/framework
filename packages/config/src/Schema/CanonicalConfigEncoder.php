<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema;

/** Versioned canonical I-JSON subset for configuration identity. @api */
final class CanonicalConfigEncoder
{
    public const PROFILE_V1 = 'waaseyaa.canonical-config-json/1';
    public const int MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE;

    /** @param array<string, mixed>|null $schema */
    public function encode(mixed $value, ?array $schema = null): string
    {
        return json_encode($this->canonicalize($value, $schema, '$'), self::JSON_FLAGS);
    }

    /** @param array<string, mixed>|null $schema */
    public function digest(string $domainId, mixed $value, ?array $schema = null): string
    {
        return $this->digestBytes($domainId, $this->encode($value, $schema));
    }

    public function digestBytes(string $domainId, string $bytes): string
    {
        if ($domainId === '' || preg_match('/^[\x21-\x7E]+$/D', $domainId) !== 1 || str_contains($domainId, "\0")) {
            throw new \InvalidArgumentException('Canonical digest domain must be non-empty printable ASCII without NUL.');
        }
        if (preg_match('//u', $bytes) !== 1) {
            throw new \InvalidArgumentException('Canonical digest bytes must be valid UTF-8.');
        }

        return 'sha256:' . hash('sha256', $domainId . "\0" . $bytes);
    }

    /** @param array<string, mixed> $schema */
    public function encodeSchema(array $schema): string
    {
        return json_encode($this->canonicalizeSchema($schema, '$schema'), self::JSON_FLAGS);
    }

    /** @param array<string, mixed>|null $schema */
    private function canonicalize(mixed $value, ?array $schema, string $path): mixed
    {
        if ($value === null || \is_bool($value)) {
            return $value;
        }
        if (\is_int($value)) {
            if ($value < -self::MAX_SAFE_INTEGER || $value > self::MAX_SAFE_INTEGER) {
                throw new \InvalidArgumentException(sprintf('Canonical integer at %s exceeds the interoperable range.', $path));
            }

            return $value;
        }
        if (\is_float($value)) {
            throw new \InvalidArgumentException(sprintf('Canonical configuration rejects floating-point value at %s.', $path));
        }
        if (\is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException(sprintf('Canonical string at %s is not valid UTF-8.', $path));
            }

            return $value;
        }
        if (!\is_array($value)) {
            throw new \InvalidArgumentException(sprintf('Canonical configuration rejects %s at %s.', get_debug_type($value), $path));
        }

        $schemaType = $schema['type'] ?? null;
        $isList = array_is_list($value);
        if ($value === [] && $schemaType === 'object') {
            return new \stdClass();
        }
        if ($schemaType === 'array' && !$isList) {
            throw new \InvalidArgumentException(sprintf('Canonical array at %s must be a list.', $path));
        }
        if ($schemaType === 'object' && $isList) {
            throw new \InvalidArgumentException(sprintf('Canonical object at %s must be a map.', $path));
        }

        if ($isList) {
            $itemSchema = isset($schema['items']) && \is_array($schema['items']) ? $schema['items'] : null;

            return array_map(
                fn(mixed $item, int $index): mixed => $this->canonicalize($item, $itemSchema, $path . '.' . $index),
                $value,
                array_keys($value),
            );
        }

        $sorted = $value;
        ksort($sorted, \SORT_STRING);
        foreach ($sorted as $key => $item) {
            if (!\is_string($key) || $key === '' || preg_match('/^[\x20-\x7E]+$/D', $key) !== 1) {
                throw new \InvalidArgumentException(sprintf('Canonical map key at %s must be non-empty ASCII.', $path));
            }
            $childSchema = null;
            if (isset($schema['properties'][$key]) && \is_array($schema['properties'][$key])) {
                $childSchema = $schema['properties'][$key];
            } elseif (isset($schema['additionalProperties']) && \is_array($schema['additionalProperties'])) {
                $childSchema = $schema['additionalProperties'];
            }
            $sorted[$key] = $this->canonicalize($item, $childSchema, $path . '.' . $key);
        }

        return $sorted;
    }

    /** @param array<string, mixed> $schema */
    private function canonicalizeSchema(array $schema, string $path): array
    {
        $sorted = $schema;
        ksort($sorted, \SORT_STRING);
        foreach ($sorted as $key => $value) {
            if ($key === '' || preg_match('/^[\x20-\x7E]+$/D', $key) !== 1) {
                throw new \InvalidArgumentException(sprintf('Canonical schema key at %s must be non-empty ASCII.', $path));
            }
            if ($key === 'properties') {
                if (!\is_array($value) || array_is_list($value) && $value !== []) {
                    throw new \InvalidArgumentException(sprintf('Canonical schema properties at %s must be a map.', $path));
                }
                if ($value === []) {
                    $sorted[$key] = new \stdClass();
                    continue;
                }
                ksort($value, \SORT_STRING);
                foreach ($value as $property => $propertySchema) {
                    if (!\is_string($property) || $property === '' || preg_match('/^[\x20-\x7E]+$/D', $property) !== 1 || !\is_array($propertySchema)) {
                        throw new \InvalidArgumentException(sprintf('Canonical schema property at %s is malformed.', $path));
                    }
                    $value[$property] = $this->canonicalizeSchema($propertySchema, $path . '.properties.' . $property);
                }
                $sorted[$key] = $value;
                continue;
            }
            if (($key === 'items' || $key === 'additionalProperties') && \is_array($value)) {
                $sorted[$key] = $this->canonicalizeSchema($value, $path . '.' . $key);
                continue;
            }
            if ($key === 'default') {
                $sorted[$key] = $this->canonicalize($value, $schema, $path . '.default');
                continue;
            }
            $sorted[$key] = $this->canonicalize($value, null, $path . '.' . $key);
        }

        return $sorted;
    }
}
