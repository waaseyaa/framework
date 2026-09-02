<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

/**
 * Canonical metadata-only exclusion policy for generic entity read surfaces.
 *
 * Authorization remains the responsibility of each surface. This policy owns
 * the structural floor: credential names, framework-internal fields,
 * application-declared internal fields, and `settings.internal`.
 * @api
 */
final readonly class FieldVisibilityPolicy
{
    private const array ALWAYS_INTERNAL_FIELDS = [
        'pass',
        'legacy_pass',
        'password',
        'password_hash',
    ];

    private const array FRAMEWORK_INTERNAL_FIELDS = [
        'node' => ['source_status', 'wp_status'],
    ];

    /** @var array<string, array<string, true>> */
    private array $internalFieldsByType;

    /** @param array<mixed> $applicationInternalFieldsByType */
    public function __construct(array $applicationInternalFieldsByType = [])
    {
        $normalized = [];
        foreach (self::FRAMEWORK_INTERNAL_FIELDS as $entityTypeId => $fields) {
            foreach ($fields as $field) {
                $normalized[$entityTypeId][$field] = true;
            }
        }
        foreach ($applicationInternalFieldsByType as $entityTypeId => $fields) {
            if (!is_string($entityTypeId) || $entityTypeId === '' || !array_is_list($fields)) {
                throw new \InvalidArgumentException(
                    'entity.internal_fields_by_type must map non-empty entity-type ids to field-name lists.',
                );
            }
            foreach ($fields as $field) {
                if (!is_string($field) || $field === '') {
                    throw new \InvalidArgumentException(sprintf(
                        'entity.internal_fields_by_type.%s contains an empty or non-string field name.',
                        $entityTypeId,
                    ));
                }
                $normalized[$entityTypeId][$field] = true;
            }
        }
        foreach ($normalized as &$fields) {
            ksort($fields);
        }
        unset($fields);
        ksort($normalized);
        $this->internalFieldsByType = $normalized;
    }

    public function isInternal(
        string $entityTypeId,
        string $field,
        ?FieldDefinitionInterface $definition = null,
    ): bool {
        return in_array($field, self::ALWAYS_INTERNAL_FIELDS, true)
            || isset($this->internalFieldsByType[$entityTypeId][$field])
            || $definition?->getSetting('internal') === true;
    }

    /** @return list<string> */
    public function internalFields(string $entityTypeId): array
    {
        return array_keys($this->internalFieldsByType[$entityTypeId] ?? []);
    }
}
