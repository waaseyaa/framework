<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Converts EntityInterface objects to JsonApiResource value objects.
 *
 * Maps entity fields to JSON:API attributes, excluding entity keys
 * (id, uuid) which become the resource's top-level id/type.
 */
final class ResourceSerializer
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
    ) {}

    /**
     * Serialize a single entity to a JsonApiResource.
     *
     * When an access handler and account are provided, fields that the account
     * cannot view are omitted from the attributes.
     */
    public function serialize(
        EntityInterface $entity,
        ?EntityAccessHandler $accessHandler = null,
        ?AccountInterface $account = null,
    ): JsonApiResource {
        $entityTypeId = $entity->getEntityTypeId();
        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        $keys = $entityType->getKeys();

        // Use UUID as the resource ID if available, otherwise fall back to entity ID.
        $resourceId = $entity->uuid() !== '' ? $entity->uuid() : (string) $entity->id();

        // Build attributes from entity values, excluding entity keys (id, uuid).
        $allValues = $entity->toArray();
        $excludedFields = $this->getExcludedFields($keys);
        $attributes = array_diff_key($allValues, array_flip($excludedFields));

        // Filter out fields the account cannot view.
        if ($accessHandler !== null && $account !== null) {
            $allowedFields = $accessHandler->filterFields($entity, array_keys($attributes), 'view', $account);
            $attributes = array_intersect_key($attributes, array_flip($allowedFields));
        }

        $attributes = $this->castAttributes($attributes, $entityType->getFieldDefinitions());

        // Build self link.
        $selfLink = $this->basePath . '/' . $entityTypeId . '/' . $resourceId;

        return new JsonApiResource(
            type: $entityTypeId,
            id: $resourceId,
            attributes: $attributes,
            links: ['self' => $selfLink],
        );
    }

    /**
     * Serialize a collection of entities to an array of JsonApiResource objects.
     *
     * @param array<EntityInterface> $entities
     * @return array<JsonApiResource>
     */
    public function serializeCollection(
        array $entities,
        ?EntityAccessHandler $accessHandler = null,
        ?AccountInterface $account = null,
    ): array {
        return array_values(array_map(
            fn(EntityInterface $entity): JsonApiResource => $this->serialize($entity, $accessHandler, $account),
            $entities,
        ));
    }

    /**
     * Get the list of field names to exclude from attributes.
     *
     * Entity keys like 'id' and 'uuid' are represented at the top level
     * of the JSON:API resource, not in attributes.
     *
     * @param array<string, string> $keys
     * @return array<string>
     */
    private function getExcludedFields(array $keys): array
    {
        $excluded = [];

        // Always exclude id and uuid keys — they become the resource's top-level id.
        if (isset($keys['id'])) {
            $excluded[] = $keys['id'];
        }
        if (isset($keys['uuid'])) {
            $excluded[] = $keys['uuid'];
        }

        return array_unique($excluded);
    }

    /**
     * Cast attribute values based on field type definitions.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, array<string, mixed>> $fieldDefinitions
     * @return array<string, mixed>
     */
    private function castAttributes(array $attributes, array $fieldDefinitions): array
    {
        foreach ($attributes as $name => $value) {
            $type = $fieldDefinitions[$name]['type'] ?? null;

            $attributes[$name] = match ($type) {
                'boolean' => (bool) $value,
                'timestamp', 'datetime' => $this->formatTimestamp($value),
                default => $value,
            };
        }

        return $attributes;
    }

    /**
     * Convert a Unix timestamp to ISO 8601 string, or null if zero/empty.
     */
    private function formatTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $ts = (int) $value;
        if ($ts === 0) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $ts))->format(\DateTimeInterface::ATOM);
    }
}
