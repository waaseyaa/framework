<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * Boot-scoped authority for fields excluded from every generic read surface.
 *
 * Field-definition `settings.internal` and application declarations converge
 * here. Credential-name deny floors remain in their consumers as independent
 * defense in depth.
 *
 * @api
 */
final readonly class InternalFieldVisibilityPolicy
{
    /** @var array<string, list<string>> */
    private const array FRAMEWORK_INTERNAL_FIELDS = [
        'node' => ['source_status', 'wp_status'],
    ];

    /** @var array<string, array<string, true>> */
    private array $internalFieldsByType;

    /** @param array<mixed> $applicationInternalFieldsByType Runtime application configuration. */
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

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        $entity = $config['entity'] ?? [];
        if (!is_array($entity)) {
            throw new \InvalidArgumentException('entity configuration must be an associative array.');
        }
        $internalFields = $entity['internal_fields_by_type'] ?? [];
        if (!is_array($internalFields)) {
            throw new \InvalidArgumentException(
                'entity.internal_fields_by_type must be an associative array.',
            );
        }

        return new self($internalFields);
    }

    public function isInternal(
        string $entityTypeId,
        string $field,
        ?FieldDefinitionInterface $definition = null,
    ): bool {
        return isset($this->internalFieldsByType[$entityTypeId][$field])
            || $definition?->getSetting('internal') === true;
    }

    /** @return list<string> */
    public function internalFields(string $entityTypeId): array
    {
        return array_keys($this->internalFieldsByType[$entityTypeId] ?? []);
    }
}
