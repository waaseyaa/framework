<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Exception\PartialAccessContextException;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * Converts EntityInterface objects to JsonApiResource value objects.
 *
 * Maps entity fields to JSON:API attributes, excluding entity keys
 * which become the resource's top-level id/type. Content entities use
 * UUID as the resource ID; config entities use their string machine name.
 *
 * Attribute values are read with {@see EntityInterface::get()} so entity
 * {@see \Waaseyaa\Entity\EntityBase::$casts} apply (#1181 ST-7); they are then
 * coerced to JSON-serializable scalars/arrays (enums, {@see \DateTimeInterface}, etc.).
 */
final class ResourceSerializer
{
    /**
     * Field names that are NEVER serialized, regardless of whether the entity
     * declares them as `#[Field(... internal: true)]`. Defense in depth for
     * entities that store credential material in raw `_data` keys.
     */
    private const ALWAYS_INTERNAL_FIELDS = ['pass', 'password', 'password_hash'];

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
        if (($accessHandler === null) !== ($account === null)) {
            throw PartialAccessContextException::forSerializer(__METHOD__);
        }

        $entityTypeId = $entity->getEntityTypeId();
        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        $keys = $entityType->getKeys();

        // Config-style entities expose a string machine name as id; content entities with a numeric
        // internal id use UUID as the public resource id when present.
        $id = $entity->id();
        $uuid = $entity->uuid();
        $resourceId = match (true) {
            $id !== null && $id !== '' && !\is_int($id) => $id,
            $uuid !== '' => $uuid,
            default => (string) ($id ?? ''),
        };

        $attributes = $this->attributesFromEntity($entity, $keys);

        // Canonical, bundle-aware field set so a content type's distinct typed
        // fields (e.g. page's body/blocks/featured_image) are filtered and cast
        // with their real definitions, not just the entity-type base fields.
        $fieldDefinitions = $this->entityTypeManager->resolveFieldDefinitions($entityTypeId, $entity->bundle());

        // Drop fields marked `internal: true` and any always-internal credential keys.
        // Runs before the per-account filter so credentials never reach EntityAccessHandler.
        $attributes = $this->filterInternalFields($attributes, $fieldDefinitions);

        // Filter out fields the account cannot view.
        if ($accessHandler !== null && $account !== null) {
            $allowedFields = $accessHandler->filterFields($entity, array_keys($attributes), 'view', $account);
            $attributes = array_intersect_key($attributes, array_flip($allowedFields));
        }

        $attributes = $this->castAttributes($attributes, $fieldDefinitions);
        $attributes = $this->normalizeAttributesForJson($attributes);

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
        if (($accessHandler === null) !== ($account === null)) {
            throw PartialAccessContextException::forSerializer(__METHOD__);
        }

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
     * Build the attributes map using {@see EntityInterface::get()} per stored field name
     * so {@see \Waaseyaa\Entity\EntityBase::$casts} apply. Keys follow {@see EntityInterface::toArray()}.
     *
     * @param array<string, string> $keys
     *
     * @return array<string, mixed>
     */
    private function attributesFromEntity(EntityInterface $entity, array $keys): array
    {
        $excluded = array_flip($this->getExcludedFields($keys));
        $attributes = [];

        foreach (EntityValues::toCastAwareMap($entity) as $fieldName => $value) {
            if (isset($excluded[$fieldName])) {
                continue;
            }
            $attributes[$fieldName] = $value;
        }

        return $attributes;
    }

    /**
     * Drop attributes that must never leave the server:
     *   1. Anything in {@see self::ALWAYS_INTERNAL_FIELDS} (`pass`, `password`, `password_hash`).
     *      Honored even when no FieldDefinition exists, so raw `_data` keys
     *      holding credential material cannot leak via JSON:API.
     *   2. Any field whose definition sets `settings['internal'] => true`
     *      (e.g. `two_factor_secret` on the User entity).
     *
     * @param array<string, mixed> $attributes
     * @param array<string, FieldDefinitionInterface> $fieldDefinitions
     * @return array<string, mixed>
     */
    private function filterInternalFields(array $attributes, array $fieldDefinitions): array
    {
        foreach (array_keys($attributes) as $name) {
            if (in_array($name, self::ALWAYS_INTERNAL_FIELDS, true)) {
                unset($attributes[$name]);
                continue;
            }

            $definition = $fieldDefinitions[$name] ?? null;
            if ($definition !== null && $definition->getSetting('internal') === true) {
                unset($attributes[$name]);
            }
        }

        return $attributes;
    }

    /**
     * Cast attribute values based on field type definitions.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, FieldDefinitionInterface> $fieldDefinitions
     * @return array<string, mixed>
     */
    private function castAttributes(array $attributes, array $fieldDefinitions): array
    {
        foreach ($attributes as $name => $value) {
            $type = isset($fieldDefinitions[$name]) ? $fieldDefinitions[$name]->getType() : null;

            $attributes[$name] = match ($type) {
                'boolean' => (bool) $value,
                'timestamp', 'datetime' => $this->formatTimestamp($value),
                default => $value,
            };
        }

        return $attributes;
    }

    /**
     * Ensure attribute values are JSON-serializable (enums, dates, nested arrays).
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private function normalizeAttributesForJson(array $attributes): array
    {
        foreach ($attributes as $name => $value) {
            $attributes[$name] = $this->normalizeValueForJson($value);
        }

        return $attributes;
    }

    private function normalizeValueForJson(mixed $value): mixed
    {
        return EntityValues::normalizeValueForJson($value);
    }

    /**
     * Convert a Unix timestamp to ISO 8601 string, or null if zero/empty.
     */
    private function formatTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        $ts = (int) $value;
        if ($ts === 0) {
            return null;
        }

        return new \DateTimeImmutable('@' . $ts)->format(\DateTimeInterface::ATOM);
    }
}
