<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint\Emitter;

use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintEntity;
use Waaseyaa\SiteContract\Blueprint\BlueprintField;
use Waaseyaa\SiteContract\Blueprint\BlueprintFieldType;
use Waaseyaa\SiteContract\Blueprint\BlueprintRelationship;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\SiteManifest;

/**
 * Emits one `src/Entity/<PascalCase(entity.id)>.php` per blueprint entity,
 * plus one `src/Entity/Enum/<PascalCase(entity.id)><PascalCase(field.id)>.php`
 * backed-enum class per declared `enum` field (FW-SITE-BLUEPRINT-01D decision
 * (f)).
 *
 * A final `ContentEntityBase` subclass with the entity type id and keys
 * hardcoded via `#[ContentEntityType]`/`#[ContentEntityKeys]`, one `#[Field]`
 * property per declared scalar field through the closed
 * {@see BlueprintFieldType} roster, and one `entity_reference` property for
 * every relationship whose `from.entity` is this entity (the validator
 * already reserves that field id against a declared-field collision).
 *
 * An `enum` field is typed as its generated backed-enum class (nullable,
 * default `null`) rather than `mixed`: `settings.enum_class` names that
 * generated FQCN explicitly, which is what `Waaseyaa\Field\Item\EnumItem`
 * requires (`EnumFieldTypeException::MISSING_ENUM_CLASS` otherwise) —
 * `FieldTypeInferrer` would also infer it from the property's PHP type
 * alone, but the explicit setting keeps the emitted attribute correct
 * independent of that inference path.
 *
 * `keys.owner` (`BlueprintEntityKeys::$owner`, validated as a relationship
 * field name for ownership policies) is intentionally NOT carried onto the
 * generated entity class: `ContentEntityKeys` has no `owner` parameter and
 * the entity runtime has no "owner" key at all. A future ownership-policy
 * emitter must re-derive the owner relationship field from the blueprint
 * itself, not from generated entity metadata.
 *
 * Field cardinality beyond 1 is a known limitation of the current `#[Field]`
 * attribute surface (`EntityMetadataReader::resolveFields()` hardcodes
 * `cardinality: 1`); every blueprint fixture in this slice declares single-
 * valued fields, and a future slice widening `#[Field]` itself would apply
 * here unchanged.
 *
 * R2-5: no emitted `#[Field(...)]` declares `read:`, so `EntityMetadataReader`
 * leaves every field's read level `null`, which `EntityValueContainer::read()`
 * treats as `FieldReadLevel::Internal` — every `get()` on a saved or loaded
 * generated entity throws `FieldReadDenied` for every caller (there is no
 * registered policy to grant the read). This is a framework-wide `#[Field]`
 * default, not specific to blueprint generation (`make:content-type` output
 * shares it), but the blueprint contract itself has no way to author a
 * field's intended read level. Left open for a later slice to close: either
 * 01D-2's engine wiring, a governance/policy emitter, or a blueprint-contract
 * extension adding a per-field `read_level` declaration this emitter would
 * then carry onto `#[Field(read: ...)]`.
 *
 * @api
 */
final class EntityClassEmitter implements BlueprintArtifactEmitterInterface
{
    /** PHP property type for each field type where it is compatible with the
     * explicit `#[Field(type: ...)]` id under `FieldTypeInferrer::isCompatible()`
     * (COMPATIBILITY_GROUPS); `mixed` where no scalar PHP type both infers
     * cleanly and stays compatible without a backing PHP construct the
     * blueprint does not declare. `enum` is handled separately — a generated
     * backed-enum class per field, see {@see self::enumFieldPlans()} — rather
     * than through this table.
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
            $enumPlans = self::enumFieldPlans($entity);
            $artifacts[] = new GeneratedArtifact(
                'src/Entity/' . self::pascalCase($entity->id) . '.php',
                $this->renderEntity($entity, $relationshipsByFromEntity[$entity->id] ?? [], $enumPlans),
            );
            foreach (self::uniqueEnumClasses($enumPlans) as $shortName => $values) {
                $artifacts[] = new GeneratedArtifact(
                    'src/Entity/Enum/' . $shortName . '.php',
                    $this->renderEnumClass($shortName, $values),
                );
            }
        }
        usort($artifacts, static fn(GeneratedArtifact $left, GeneratedArtifact $right): int => strcmp($left->path, $right->path));

        return new BlueprintEmission($artifacts);
    }

    /** @param list<BlueprintRelationship> $relationships @param array<string, array{shortName: string, values: list<string>}> $enumPlans */
    private function renderEntity(BlueprintEntity $entity, array $relationships, array $enumPlans): string
    {
        $className = self::pascalCase($entity->id);
        $safeLabel = self::singleQuoted($entity->label);
        $safeLabelField = self::singleQuoted($entity->keys->label);

        $keysArgs = ["id: '{$this->safeId($entity->keys->id)}'", "uuid: '{$this->safeId($entity->keys->uuid)}'", "label: {$safeLabelField}"];
        if ($entity->keys->revision !== null) {
            $keysArgs[] = "revision: '{$this->safeId($entity->keys->revision)}'";
        }
        if ($entity->keys->langcode !== null) {
            $keysArgs[] = "langcode: '{$this->safeId($entity->keys->langcode)}'";
        }
        if ($entity->keys->defaultLangcode !== null) {
            $keysArgs[] = "default_langcode: '{$this->safeId($entity->keys->defaultLangcode)}'";
        }

        $fieldBlock = $this->renderFieldBlock($entity, $relationships, $enumPlans);
        $enumUseBlock = self::renderEnumUseBlock($enumPlans);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Entity;

            {$enumUseBlock}use Waaseyaa\\Entity\\Attribute\\ContentEntityKeys;
            use Waaseyaa\\Entity\\Attribute\\ContentEntityType;
            use Waaseyaa\\Entity\\Attribute\\Field;
            use Waaseyaa\\Entity\\ContentEntityBase;

            #[ContentEntityType(id: '{$this->safeId($entity->id)}', label: {$safeLabel}, storageBackend: '{$entity->storage->value}')]
            #[ContentEntityKeys(
            \x20   {$this->joinArgs($keysArgs)}
            )]
            final class {$className} extends ContentEntityBase
            {
            {$fieldBlock}
            }

            PHP;
    }

    /** @param array<string, array{shortName: string, values: list<string>}> $enumPlans */
    private static function renderEnumUseBlock(array $enumPlans): string
    {
        if ($enumPlans === []) {
            return '';
        }

        $names = array_values(array_unique(array_map(
            static fn(array $plan): string => $plan['shortName'],
            $enumPlans,
        )));
        sort($names, SORT_STRING);

        $lines = array_map(static fn(string $name): string => "use App\\Entity\\Enum\\{$name};\n", $names);

        return implode('', $lines);
    }

    /** @param list<BlueprintRelationship> $relationships @param array<string, array{shortName: string, values: list<string>}> $enumPlans */
    private function renderFieldBlock(BlueprintEntity $entity, array $relationships, array $enumPlans): string
    {
        $lines = [];
        foreach (self::sortedFields($entity) as $field) {
            $attrArgs = [
                "type: '{$field->type->value}'",
                'label: ' . self::singleQuoted(ucwords(strtr($field->id, '_', ' '))),
                'required: ' . ($field->required ? 'true' : 'false'),
                'translatable: ' . ($field->translatable ? 'true' : 'false'),
                'revisionable: ' . ($field->revisionable ? 'true' : 'false'),
            ];
            if ($field->indexed) {
                $attrArgs[] = 'indexed: true';
            }

            if ($field->type === BlueprintFieldType::Enum) {
                $shortName = $enumPlans[$field->id]['shortName'];
                $attrArgs[] = "settings: ['enum_class' => \\App\\Entity\\Enum\\{$shortName}::class]";
                $lines[] = "    #[Field({$this->joinArgs($attrArgs)})]";
                $lines[] = "    public ?{$shortName} \${$this->safeId($field->id)} = null;";
                $lines[] = '';
                continue;
            }

            [$phpType, $default] = self::PHP_TYPE_MAP[$field->type->value];
            $lines[] = "    #[Field({$this->joinArgs($attrArgs)})]";
            $lines[] = "    public {$phpType} \${$this->safeId($field->id)} = {$default};";
            $lines[] = '';
        }

        foreach ($relationships as $relationship) {
            $label = self::singleQuoted(ucwords(strtr($relationship->fromField, '_', ' ')));
            $target = self::singleQuoted($relationship->toEntity);
            $required = $relationship->required ? 'true' : 'false';
            $lines[] = "    #[Field(type: 'entity_reference', label: {$label}, required: {$required}, settings: ['target_entity_type_id' => {$target}])]";
            $lines[] = "    public ?int \${$this->safeId($relationship->fromField)} = null;";
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    /**
     * One enum-field plan per declared `enum` field on this entity, keyed by
     * field id: the generated backed-enum class's short name
     * (`<PascalCase(entity.id)><PascalCase(field.id)>`, disambiguated across
     * entities so two entities may each declare a same-named field) and its
     * sorted, unique case values.
     *
     * @return array<string, array{shortName: string, values: list<string>}>
     */
    private static function enumFieldPlans(BlueprintEntity $entity): array
    {
        $plans = [];
        foreach (self::sortedFields($entity) as $field) {
            if ($field->type !== BlueprintFieldType::Enum) {
                continue;
            }
            $values = $field->values ?? [];
            sort($values, SORT_STRING);
            $plans[$field->id] = [
                'shortName' => self::pascalCase($entity->id) . self::pascalCase($field->id),
                'values' => $values,
            ];
        }

        return $plans;
    }

    /**
     * @param array<string, array{shortName: string, values: list<string>}> $enumPlans
     * @return array<string, list<string>> shortName => values
     */
    private static function uniqueEnumClasses(array $enumPlans): array
    {
        $classes = [];
        foreach ($enumPlans as $plan) {
            $classes[$plan['shortName']] = $plan['values'];
        }
        ksort($classes, SORT_STRING);

        return $classes;
    }

    /** @param list<string> $values */
    private function renderEnumClass(string $shortName, array $values): string
    {
        $lines = [];
        foreach ($values as $value) {
            $caseName = self::enumCaseName($value);
            $lines[] = "    case {$caseName} = " . self::singleQuoted($value) . ';';
        }
        $cases = implode("\n", $lines);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Entity\\Enum;

            /**
             * Generated by Waaseyaa\\CLI\\Site\\Blueprint\\ApplicationBlueprintCompiler.
             * Do not edit by hand.
             */
            enum {$shortName}: string
            {
            {$cases}
            }

            PHP;
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
     * value, or class name must be hyphen-free.
     *
     * `Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompiler` asserts the
     * same grammar over the whole blueprint before invoking any emitter,
     * raising a coded `GEN006_MALICIOUS_IDENTIFIER` refusal for a
     * project-state input the SITE0xx grammar admits but PHP cannot
     * represent as an identifier. This check is therefore a defensive
     * invariant that should be unreachable in practice, not the primary
     * refusal path — a bare `\InvalidArgumentException` here signals a
     * compiler defect (the pre-check and this check have drifted apart),
     * never a project-state refusal.
     */
    private function safeId(string $id): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $id) !== 1) {
            throw new \InvalidArgumentException("Blueprint id is not a valid PHP identifier segment: {$id}");
        }

        return $id;
    }

    /**
     * An enum field's declared `values` are free-form non-empty strings
     * (`ApplicationBlueprintParser`), not identifiers. Converting one to a
     * PHP enum case name is best-effort (`pascalCase`); a value that cannot
     * become a valid identifier segment refuses rather than silently
     * mangling or colliding with another case.
     */
    private static function enumCaseName(string $value): string
    {
        $caseName = self::pascalCase($value);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $caseName) !== 1) {
            throw new \InvalidArgumentException("Blueprint enum value is not representable as a PHP enum case name: {$value}");
        }

        return $caseName;
    }

    /** Escapes only backslash and single quote so the result is safe to
     * interpolate between single quotes in generated PHP source — unlike
     * `addslashes()`, which also escapes double quotes that single-quoted
     * PHP strings never unescape, corrupting any label containing one.
     */
    private static function singleQuoted(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    private static function pascalCase(string $id): string
    {
        return str_replace('_', '', ucwords(str_replace('-', '_', $id), '_'));
    }
}
