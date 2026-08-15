<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema;

/**
 * Validates config data against JSON-Schema-like definitions.
 *
 * Schemas follow a subset of JSON Schema with the following supported keywords:
 * - type: string, integer, boolean, array, object
 * - properties: nested property schemas (for type: object)
 * - required: list of required property names
 * - enum: allowed values
 * - minimum / maximum: numeric range constraints
 * - default: default value (missing values with defaults are not violations)
 * - nullable: explicit boolean permitting null in addition to one supported type
 * @api
 */
final class ConfigSchemaValidator
{
    private const array REGISTRATION_KEYWORDS = [
        'additionalProperties',
        'default',
        'dialect',
        'enum',
        'items',
        'maximum',
        'minimum',
        'nullable',
        'properties',
        'required',
        'type',
    ];

    private const array REGISTRATION_TYPES = [
        'array',
        'boolean',
        'integer',
        'object',
        'string',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    /**
     * Register a schema definition for a config name.
     *
     * @param string $configName The config name (e.g., "system.site").
     * @param array<string, mixed> $schema The schema definition.
     */
    public function registerSchema(string $configName, array $schema): void
    {
        $this->assertSchemaDefinition($schema, '$');
        $this->schemas[$configName] = $schema;
    }

    /**
     * Check if a schema is registered for the given config name.
     */
    public function hasSchema(string $configName): bool
    {
        return isset($this->schemas[$configName]);
    }

    /**
     * Get the registered schema for a config name, or null if none.
     *
     * @return array<string, mixed>|null
     */
    public function getSchema(string $configName): ?array
    {
        return $this->schemas[$configName] ?? null;
    }

    /**
     * Validate config data using the registered schema for the given config name.
     *
     * @param string $configName The config name to look up the schema.
     * @param array<string, mixed> $data The config data to validate.
     * @return SchemaViolation[]
     *
     * @throws \RuntimeException If no schema is registered for the config name.
     */
    public function validateConfig(string $configName, array $data): array
    {
        if (!$this->hasSchema($configName)) {
            throw new \RuntimeException(sprintf(
                'No schema registered for config "%s".',
                $configName,
            ));
        }

        return $this->validate($data, $this->schemas[$configName]);
    }

    /**
     * Validate config data against an explicit schema definition.
     *
     * @param array<string, mixed> $data The config data to validate.
     * @param array<string, mixed> $schema The schema to validate against.
     * @return SchemaViolation[]
     */
    public function validate(array $data, array $schema): array
    {
        $this->assertSchemaDefinition($schema, '$');

        return $this->validateValue($data, $schema, '');
    }

    /**
     * Return a recursively default-materialized effective document without
     * mutating the caller's authored input.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function materialize(array $data, array $schema): array
    {
        $violations = $this->validate($data, $schema);
        if ($violations !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Configuration cannot be materialized: %s: %s',
                $violations[0]->path,
                $violations[0]->message,
            ));
        }

        $effective = $this->materializeValue($data, $schema);
        if (!\is_array($effective)) {
            throw new \LogicException('A root configuration schema must materialize an object.');
        }

        return $effective;
    }

    /**
     * @return SchemaViolation[]
     */
    private function validateValue(mixed $value, array $schema, string $path): array
    {
        $violations = [];

        if ($value === null && ($schema['nullable'] ?? false) === true) {
            return [];
        }

        // Type checking
        if (isset($schema['type'])) {
            $typeViolation = $this->checkType($value, $schema['type'], $path);
            if ($typeViolation !== null) {
                $violations[] = $typeViolation;
                // If type is wrong, skip further checks on this value
                return $violations;
            }
        }

        // Enum checking
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            if (!in_array($value, $schema['enum'], true)) {
                $violations[] = new SchemaViolation(
                    path: $path,
                    message: sprintf(
                        'Value must be one of the allowed enum values: [%s]. Got: %s.',
                        implode(', ', array_map(fn($v) => (string) $v, $schema['enum'])),
                        is_scalar($value) ? (string) $value : gettype($value),
                    ),
                );
            }
        }

        // Minimum checking (numeric)
        if (isset($schema['minimum']) && is_numeric($value)) {
            if ($value < $schema['minimum']) {
                $violations[] = new SchemaViolation(
                    path: $path,
                    message: sprintf(
                        'Value %s is less than minimum %s.',
                        (string) $value,
                        (string) $schema['minimum'],
                    ),
                );
            }
        }

        // Maximum checking (numeric)
        if (isset($schema['maximum']) && is_numeric($value)) {
            if ($value > $schema['maximum']) {
                $violations[] = new SchemaViolation(
                    path: $path,
                    message: sprintf(
                        'Value %s is greater than maximum %s.',
                        (string) $value,
                        (string) $schema['maximum'],
                    ),
                );
            }
        }

        // Object properties validation
        if (($schema['type'] ?? null) === 'object' && isset($schema['properties']) && is_array($value)) {
            $violations = array_merge(
                $violations,
                $this->validateObjectProperties($value, $schema, $path),
            );
        }

        if (($schema['type'] ?? null) === 'array' && \is_array($value) && isset($schema['items']) && \is_array($schema['items'])) {
            foreach ($value as $index => $item) {
                $violations = array_merge(
                    $violations,
                    $this->validateValue($item, $schema['items'], $this->joinPath($path, (string) $index)),
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $schema
     * @return SchemaViolation[]
     */
    private function validateObjectProperties(array $data, array $schema, string $path): array
    {
        $violations = [];
        $properties = $schema['properties'] ?? [];

        // Check required properties
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $requiredProp) {
                if (!array_key_exists($requiredProp, $data)) {
                    // Check if the property has a default
                    $propSchema = $properties[$requiredProp] ?? [];
                    if (!array_key_exists('default', $propSchema)) {
                        $violations[] = new SchemaViolation(
                            path: $this->joinPath($path, $requiredProp),
                            message: sprintf('Property "%s" is required but missing.', $requiredProp),
                        );
                    }
                }
            }
        }

        // Validate each property that exists in data and has a schema
        foreach ($properties as $propName => $propSchema) {
            if (!array_key_exists($propName, $data)) {
                // Property not present; skip (required check is above)
                continue;
            }

            $propPath = $this->joinPath($path, $propName);
            $violations = array_merge(
                $violations,
                $this->validateValue($data[$propName], $propSchema, $propPath),
            );
        }

        foreach ($data as $propName => $value) {
            if (\array_key_exists($propName, $properties)) {
                continue;
            }

            $additional = $schema['additionalProperties'] ?? false;
            if (\is_array($additional)) {
                $violations = array_merge(
                    $violations,
                    $this->validateValue($value, $additional, $this->joinPath($path, $propName)),
                );
                continue;
            }

            $violations[] = new SchemaViolation(
                path: $this->joinPath($path, $propName),
                message: sprintf('Property "%s" is not declared by the closed configuration schema.', $propName),
            );
        }

        return $violations;
    }

    private function checkType(mixed $value, string $expectedType, string $path): ?SchemaViolation
    {
        $valid = match ($expectedType) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && (array_values($value) === $value || $value === []),
            'object' => is_array($value),
            default => true, // Unknown types pass
        };

        if (!$valid) {
            return new SchemaViolation(
                path: $path,
                message: sprintf(
                    'Expected type "%s", got "%s".',
                    $expectedType,
                    get_debug_type($value),
                ),
            );
        }

        return null;
    }

    private function joinPath(string $base, string $key): string
    {
        if ($base === '') {
            return $key;
        }

        return $base . '.' . $key;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function assertSchemaDefinition(array $schema, string $path): void
    {
        foreach (array_keys($schema) as $keyword) {
            if (!\in_array($keyword, self::REGISTRATION_KEYWORDS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Unsupported configuration schema keyword "%s" at %s.',
                    $keyword,
                    $path,
                ));
            }
        }

        $type = $schema['type'] ?? null;
        if (!\is_string($type) || !\in_array($type, self::REGISTRATION_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported configuration schema type "%s" at %s.',
                \is_scalar($type) ? (string) $type : get_debug_type($type),
                $path,
            ));
        }

        if (isset($schema['dialect']) && $schema['dialect'] !== ConfigSchemaRegistry::DIALECT_V1) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported configuration schema dialect "%s" at %s.',
                \is_scalar($schema['dialect']) ? (string) $schema['dialect'] : get_debug_type($schema['dialect']),
                $path,
            ));
        }

        if (isset($schema['nullable']) && !\is_bool($schema['nullable'])) {
            throw new \InvalidArgumentException(sprintf('Configuration schema nullable at %s must be boolean.', $path));
        }

        if (isset($schema['properties'])) {
            if ($type !== 'object' || !\is_array($schema['properties'])) {
                throw new \InvalidArgumentException(sprintf('Configuration schema properties at %s must be an object map.', $path));
            }
            foreach ($schema['properties'] as $name => $propertySchema) {
                if (!\is_string($name) || $name === '' || !\is_array($propertySchema)) {
                    throw new \InvalidArgumentException(sprintf('Configuration schema property at %s is malformed.', $path));
                }
                $this->assertSchemaDefinition($propertySchema, $this->joinPath($path, $name));
            }
        }

        if ($type === 'array') {
            if (!isset($schema['items']) || !\is_array($schema['items'])) {
                throw new \InvalidArgumentException(sprintf('Configuration array schema at %s requires one items schema.', $path));
            }
            $this->assertSchemaDefinition($schema['items'], $path . '[]');
        } elseif (isset($schema['items'])) {
            throw new \InvalidArgumentException(sprintf('Configuration schema items at %s requires type array.', $path));
        }

        if (isset($schema['required'])) {
            if ($type !== 'object' || !\is_array($schema['required']) || array_values($schema['required']) !== $schema['required']) {
                throw new \InvalidArgumentException(sprintf('Configuration schema required at %s must be a list.', $path));
            }
            foreach ($schema['required'] as $required) {
                if (!\is_string($required) || !isset($schema['properties'][$required])) {
                    throw new \InvalidArgumentException(sprintf('Configuration schema required entry at %s is undeclared.', $path));
                }
            }
        }

        if (isset($schema['additionalProperties'])) {
            $additional = $schema['additionalProperties'];
            if ($type !== 'object' || ($additional !== false && !\is_array($additional))) {
                throw new \InvalidArgumentException(sprintf(
                    'Configuration schema additionalProperties at %s must be false or a typed schema.',
                    $path,
                ));
            }
            if (\is_array($additional)) {
                $this->assertSchemaDefinition($additional, $path . '.*');
            }
        }

        if (\array_key_exists('default', $schema)) {
            $violations = $this->validateValue($schema['default'], $schema, $path . '.default');
            if ($violations !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'Configuration schema default at %s is invalid: %s',
                    $path,
                    $violations[0]->message,
                ));
            }
        }
    }

    /** @param array<string, mixed> $schema */
    private function materializeValue(mixed $value, array $schema): mixed
    {
        if (($schema['type'] ?? null) === 'object' && \is_array($value)) {
            $effective = $value;
            foreach (($schema['properties'] ?? []) as $name => $propertySchema) {
                if (!\array_key_exists($name, $effective)) {
                    if (!\array_key_exists('default', $propertySchema)) {
                        continue;
                    }
                    $effective[$name] = $propertySchema['default'];
                }
                $effective[$name] = $this->materializeValue($effective[$name], $propertySchema);
            }
            return $effective;
        }

        if (($schema['type'] ?? null) === 'array' && \is_array($value) && isset($schema['items']) && \is_array($schema['items'])) {
            return array_map(fn(mixed $item): mixed => $this->materializeValue($item, $schema['items']), $value);
        }

        return $value;
    }
}
