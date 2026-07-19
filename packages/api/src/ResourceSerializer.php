<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Exception\PartialAccessContextException;
use Waaseyaa\Api\Sanitizer\RichTextSanitizer;
use Waaseyaa\Entity\EntityBase;
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
 *
 * R13 WP2 (audit A11, SECURITY): text_long ("richtext") attributes are run
 * through {@see RichTextSanitizer} in {@see castAttributes()} -- this is the
 * canonical read/serialization chokepoint for JSON:API, admin-surface, and
 * the SSR Markdown presenter, all of which construct a ResourceSerializer.
 * The sanitizer is applied only here (never at write time), so the stored
 * entity value is left byte-for-byte as authored (non-lossy at rest).
 */
final class ResourceSerializer
{
    /**
     * Field names that are NEVER serialized, regardless of whether the entity
     * declares them as `#[Field(... internal: true)]`. Defense in depth for
     * entities that store credential material in raw `_data` keys.
     */
    private const ALWAYS_INTERNAL_FIELDS = ['pass', 'password', 'password_hash'];

    private readonly RichTextSanitizer $richTextSanitizer;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
        ?RichTextSanitizer $richTextSanitizer = null,
    ) {
        // PHP 8.4 constructor-default gotcha (see CLAUDE.md): resolve the
        // default in the body rather than as a parameter default, so every
        // existing `new ResourceSerializer($manager)` callsite keeps working
        // and still gets sanitization for free.
        $this->richTextSanitizer = $richTextSanitizer ?? new RichTextSanitizer();
    }

    /**
     * Serialize a single entity to a JsonApiResource.
     *
     * When an access handler and account are provided, fields that the account
     * cannot view are omitted from the attributes.
     *
     * @param \Waaseyaa\Access\AuthorizationPrincipalInterface|null $account
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

        // Canonical, bundle-aware field set so a content type's distinct typed
        // fields (e.g. page's body/blocks/featured_image) are filtered and cast
        // with their real definitions, not just the entity-type base fields.
        $fieldDefinitions = $this->entityTypeManager->resolveFieldDefinitions($entityTypeId, $entity->bundle());

        // Decide the projection from names and metadata before reading any values.
        // This is required for Protected fields whose policy denies this principal:
        // reading every value first would activate the guard before filterFields()
        // had a chance to conceal the field.
        $fieldNames = EntityValues::ordinaryFieldNames($entity);
        $fieldNames = array_keys($this->filterInternalFields(array_fill_keys($fieldNames, true), $fieldDefinitions));

        // Filter out fields the account cannot view.
        if ($accessHandler !== null && $account !== null) {
            $fieldNames = $accessHandler->filterFields($entity, $fieldNames, 'view', $account);
        } elseif ($entity instanceof EntityBase) {
            $fieldNames = array_values(array_filter(
                $fieldNames,
                static fn(string $field): bool => $entity->fieldReadLevel($field) === \Waaseyaa\Entity\FieldReadLevel::Public,
            ));
        }

        $attributes = $this->attributesFromEntity($entity, $keys, $fieldNames);

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
     * @param \Waaseyaa\Access\AuthorizationPrincipalInterface|null $account
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
     * @param list<string> $fieldNames
     *
     * @return array<string, mixed>
     */
    private function attributesFromEntity(EntityInterface $entity, array $keys, array $fieldNames): array
    {
        $excluded = array_flip($this->getExcludedFields($keys));
        $attributes = [];

        foreach (EntityValues::toCastAwareMap($entity, $fieldNames) as $fieldName => $value) {
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
     * R13 WP2 (audit A11, SECURITY): a text_long ("richtext") value is run
     * through {@see RichTextSanitizer} here, before any other cast, so raw
     * <script>/event-handler markup an author saved never reaches a JSON:API,
     * admin-surface, or Markdown consumer. This is a READ-time transform
     * only -- the entity's stored value (and $attributes as passed into this
     * method) is untouched; only the returned copy is sanitized.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, FieldDefinitionInterface> $fieldDefinitions
     * @return array<string, mixed>
     */
    private function castAttributes(array $attributes, array $fieldDefinitions): array
    {
        foreach ($attributes as $name => $value) {
            $type = isset($fieldDefinitions[$name]) ? $fieldDefinitions[$name]->getType() : null;

            if ($type !== null && RichTextSanitizer::isHtmlFieldType($type)) {
                $attributes[$name] = $this->richTextSanitizer->sanitizeValue($value);
                continue;
            }

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
