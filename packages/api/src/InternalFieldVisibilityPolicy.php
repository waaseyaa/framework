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
    private \Waaseyaa\Field\FieldVisibilityPolicy $delegate;

    /** @param array<mixed> $applicationInternalFieldsByType Runtime application configuration. */
    public function __construct(array $applicationInternalFieldsByType = [])
    {
        $this->delegate = new \Waaseyaa\Field\FieldVisibilityPolicy($applicationInternalFieldsByType);
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
        return $this->delegate->isInternal($entityTypeId, $field, $definition);
    }

    /** @return list<string> */
    public function internalFields(string $entityTypeId): array
    {
        return $this->delegate->internalFields($entityTypeId);
    }
}
