<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Schema;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Field\FieldSchemaAuthority;
use Waaseyaa\Field\FieldTypeManager;

/**
 * Generates JSON Schema (draft 2020-12) for entity types.
 *
 * Inspects entity type definitions and produces a standards-compliant
 * JSON Schema describing the shape of each entity type's data.
 *
 * @api
 */
final class EntityJsonSchemaGenerator
{
    private readonly FieldSchemaAuthority $fieldSchemas;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        ?FieldSchemaAuthority $fieldSchemas = null,
    ) {
        $this->fieldSchemas = $fieldSchemas ?? new FieldSchemaAuthority(new FieldTypeManager());
    }

    /**
     * Generate JSON Schema for a single entity type.
     *
     * @return array<string, mixed> JSON Schema array
     */
    public function generate(
        string $entityTypeId,
        EntityInterface $entity,
        EntityAccessHandler $accessHandler,
        AuthorizationPrincipalInterface $account,
    ): array {
        if ($entity->getEntityTypeId() !== $entityTypeId) {
            throw new \InvalidArgumentException('The schema subject must match the requested entity type.');
        }
        if (!$accessHandler->check($entity, 'view', $account)->isAllowed()) {
            throw new \DomainException('Entity schema introspection requires explicit view access.');
        }
        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        $fields = $this->entityTypeManager->resolveFieldDefinitions($entityTypeId, $entity->bundle());
        foreach ($fields as $name => $_definition) {
            if ($accessHandler->checkFieldAccess($entity, $name, 'view', $account)->isForbidden()) {
                unset($fields[$name]);
            }
        }

        return $this->fieldSchemas->entitySchema($entityType, $fields);
    }

    /**
     * Generate JSON Schema for all registered entity types.
     *
     * @return array<string, array<string, mixed>> Keyed by entity type ID
     */
}
