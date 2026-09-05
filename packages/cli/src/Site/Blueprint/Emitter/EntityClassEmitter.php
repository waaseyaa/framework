<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint\Emitter;

use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintEntity;
use Waaseyaa\SiteContract\Blueprint\BlueprintField;
use Waaseyaa\SiteContract\Blueprint\BlueprintFieldType;
use Waaseyaa\SiteContract\Blueprint\BlueprintRelationship;
use Waaseyaa\SiteContract\SiteManifest;

/**
 * Emits one `src/Entity/<PascalCase(entity.id)>.php` per blueprint entity
 * (FW-SITE-BLUEPRINT-01D decision (f)).
 *
 * A final `ContentEntityBase` subclass with the entity type id and keys
 * hardcoded via `#[ContentEntityType]`/`#[ContentEntityKeys]`, one `#[Field]`
 * property per declared scalar field through the closed
 * {@see BlueprintFieldType} roster, and one `entity_reference` property for
 * every relationship whose `from.entity` is this entity (the validator
 * already reserves that field id against a declared-field collision).
 *
 * Field cardinality beyond 1 is a known limitation of the current `#[Field]`
 * attribute surface (`EntityMetadataReader::resolveFields()` hardcodes
 * `cardinality: 1`); every blueprint fixture in this slice declares single-
 * valued fields, and a future slice widening `#[Field]` itself would apply
 * here unchanged.
 *
 * @api
 */
final class EntityClassEmitter implements BlueprintArtifactEmitterInterface
{
    /** PHP property type for each field type where it is compatible with the
     * explicit `#[Field(type: ...)]` id under `FieldTypeInferrer::isCompatible()`
     * (COMPATIBILITY_GROUPS); `mixed` where no scalar PHP type both infers
     * cleanly and stays compatible without a backing PHP construct the
     * blueprint does not declare (a backed enum class for `enum`, for
     * example).
     *
     * @var array<string, array{0: string, 1: string}> type => [phpType, defaultLiteral]
     */
    private const array PHP_TYPE_MAP = [
        'string' => ['string', "''"],
        'text' => ['string', "''"],
        'integer' => ['?int', 'null'],
        'float' => ['?float', 'null'],
        'decimal' => ['?float', 'null'],
        'boolean' => ['bool', 'false'],
        'date' => ['mixed', 'null'],
        'datetime' => ['mixed', 'null'],
        'email' => ['string', "''"],
        'link' => ['string', "''"],
        'json' => ['array', '[]'],
        'enum' => ['mixed', 'null'],
        'list' => ['mixed', 'null'],
    ];

    public function id(): string
    {
        return 'entity-class';
    }

    public function emit(ApplicationBlueprint $blueprint, SiteManifest $manifest): BlueprintEmission
    {
        $relationshipsByFromEntity = [];
        foreach ($blueprint->relationships as $relationship) {
            $relationshipsByFromEntity[$relationship->fromEntity][] = $relationship;
        }

        $artifacts = [];
        foreach ($blueprint->entities as $entity) {
            $artifacts[] = new \Waaseyaa\SiteContract\Generation\GeneratedArtifact(
                'src/Entity/' . self::pascalCase($entity->id) . '.php',
                $this->renderEntity($entity, $relationshipsByFromEntity[$entity->id] ?? []),
            );
        }
        usort($artifacts, static fn($left, $right): int => strcmp($left->path, $right->path));

        return new BlueprintEmission($artifacts);
    }

    /** @param list<BlueprintRelationship> $relationships */
    private function renderEntity(BlueprintEntity $entity, array $relationships): string
    {
        $className = self::pascalCase($entity->id);
        $safeLabel = addslashes($entity->label);
        $safeLabelField = addslashes($entity->keys->label);

        $keysArgs = ["id: '{$this->safeId($entity->keys->id)}'", "uuid: '{$this->safeId($entity->keys->uuid)}'", "label: '{$safeLabelField}'"];
        if ($entity->keys->revision !== null) {
            $keysArgs[] = "revision: '{$this->safeId($entity->keys->revision)}'";
        }
        if ($entity->keys->langcode !== null) {
            $keysArgs[] = "langcode: '{$this->safeId($entity->keys->langcode)}'";
        }
        if ($entity->keys->defaultLangcode !== null) {
            $keysArgs[] = "default_langcode: '{$this->safeId($entity->keys->defaultLangcode)}'";
        }

        $fieldBlock = $this->renderFieldBlock($entity, $relationships);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Entity;

            use Waaseyaa\\Entity\\Attribute\\ContentEntityKeys;
            use Waaseyaa\\Entity\\Attribute\\ContentEntityType;
            use Waaseyaa\\Entity\\Attribute\\Field;
            use Waaseyaa\\Entity\\ContentEntityBase;

            #[ContentEntityType(id: '{$this->safeId($entity->id)}', label: '{$safeLabel}', storageBackend: '{$entity->storage->value}')]
            #[ContentEntityKeys(
            \x20   {$this->joinArgs($keysArgs)}
            )]
            final class {$className} extends ContentEntityBase
            {
            {$fieldBlock}
            }

            PHP;
    }

    /** @param list<BlueprintRelationship> $relationships */
    private function renderFieldBlock(BlueprintEntity $entity, array $relationships): string
    {
        $lines = [];
        foreach (self::sortedFields($entity) as $field) {
            [$phpType, $default] = self::PHP_TYPE_MAP[$field->type->value];
            $attrArgs = [
                "type: '{$field->type->value}'",
                'label: \'' . addslashes(ucwords(strtr($field->id, '_', ' '))) . '\'',
                'required: ' . ($field->required ? 'true' : 'false'),
                'translatable: ' . ($field->translatable ? 'true' : 'false'),
                'revisionable: ' . ($field->revisionable ? 'true' : 'false'),
            ];
            if ($field->indexed) {
                $attrArgs[] = 'indexed: true';
            }
            if ($field->values !== null) {
                $values = $field->values;
                sort($values, SORT_STRING);
                $literal = '[' . implode(', ', array_map(static fn(string $value): string => "'" . addslashes($value) . "'", $values)) . ']';
                $attrArgs[] = "settings: ['values' => {$literal}]";
            }
            $lines[] = "    #[Field({$this->joinArgs($attrArgs)})]";
            $lines[] = "    public {$phpType} \${$this->safeId($field->id)} = {$default};";
            $lines[] = '';
        }

        foreach ($relationships as $relationship) {
            $label = addslashes(ucwords(strtr($relationship->fromField, '_', ' ')));
            $target = addslashes($relationship->toEntity);
            $required = $relationship->required ? 'true' : 'false';
            $lines[] = "    #[Field(type: 'entity_reference', label: '{$label}', required: {$required}, settings: ['target_entity_type_id' => '{$target}'])]";
            $lines[] = "    public ?int \${$this->safeId($relationship->fromField)} = null;";
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    /** @return list<BlueprintField> */
    private static function sortedFields(BlueprintEntity $entity): array
    {
        $fields = array_values($entity->fields);
        usort($fields, static fn(BlueprintField $left, BlueprintField $right): int => strcmp($left->id, $right->id));

        return $fields;
    }

    private function joinArgs(array $args): string
    {
        return implode(', ', $args);
    }

    /**
     * Blueprint ids are validated against `/^[a-z][a-z0-9_-]*$/D`
     * (`ManifestShapeReader::id()`), which allows a hyphen — not a valid PHP
     * identifier character. An id headed for a PHP property name, entity-key
     * value, or class name must be hyphen-free; refuse rather than silently
     * rewrite (a rewrite risks a namespace collision the validator never
     * checked for).
     */
    private function safeId(string $id): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $id) !== 1) {
            throw new \InvalidArgumentException("Blueprint id is not a valid PHP identifier segment: {$id}");
        }

        return $id;
    }

    private static function pascalCase(string $id): string
    {
        return str_replace('_', '', ucwords(str_replace('-', '_', $id), '_'));
    }
}
