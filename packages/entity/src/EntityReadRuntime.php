<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\Exception\EntityMetadataException;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldReadMetadataResolver;

/** Process-wide compiled field-read layout and late-bound guard runtime. @internal */
final class EntityReadRuntime
{
    private static ?EntityReadLayoutGeneration $generation = null;

    private static ?FieldReadMetadataResolver $metadataResolver = null;

    private static ?FieldDefinitionRegistryInterface $fieldRegistry = null;

    private static ?EntityValueReadGuardInterface $guard = null;

    /** @var array<string, EntityReadLayout> */
    private static array $layouts = [];

    private function __construct() {}

    public static function installGuard(?EntityValueReadGuardInterface $guard): void
    {
        self::$guard = $guard;
    }

    public static function guard(): ?EntityValueReadGuardInterface
    {
        return self::$guard;
    }

    public static function installFieldRegistry(?FieldDefinitionRegistryInterface $registry): void
    {
        if (self::$fieldRegistry === $registry) {
            return;
        }
        self::$fieldRegistry = $registry;
        self::invalidateLayouts();
    }

    public static function invalidateLayouts(): void
    {
        self::generation()->advance();
        self::$layouts = [];
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys
     * @param array<string, FieldDefinitionInterface> $entityTypeDefinitions
     */
    public static function layoutFor(
        string $class,
        array $values,
        string $entityTypeId,
        array $entityKeys,
        ?FieldDefinitionRegistryInterface $fieldRegistry = null,
        bool $registeredEntityType = false,
        array $entityTypeDefinitions = [],
    ): EntityReadLayout {
        $bundleKey = $entityKeys['bundle'] ?? 'bundle';
        $bundle = (string) ($values[$bundleKey] ?? $entityTypeId);
        $bundle = $bundle !== '' ? $bundle : $entityTypeId;
        $registry = $fieldRegistry ?? self::$fieldRegistry;
        [$definitions, $classIsRegistered] = self::classDefinitions($class);
        $registeredEntityType = $registeredEntityType || $classIsRegistered;
        $definitions = self::mergeReadDefinitions($entityTypeId, $definitions, $entityTypeDefinitions);
        if ($registry !== null) {
            $definitions = self::mergeReadDefinitions(
                $entityTypeId,
                $definitions,
                $registry->coreFieldsFor($entityTypeId),
                $registry->bundleFieldsFor($entityTypeId, $bundle),
            );
        }

        $fieldNames = array_values(array_unique(array_merge(array_keys($values), array_keys($definitions))));
        sort($fieldNames);
        $classificationInputs = [];
        foreach ($definitions as $name => $definition) {
            $level = self::metadataResolver()->resolve($definition)->level ?? FieldReadLevel::Internal;
            $classificationInputs[] = $name . ':' . $level->value . ':'
                . ($definition->getSetting('authorizationInput') === true ? 'auth' : 'ordinary');
        }
        sort($classificationInputs);
        $cacheKey = implode("\0", [
            $class,
            $entityTypeId,
            $bundle,
            $registeredEntityType ? 'registered' : 'fixture',
            (string) self::generation()->current(),
            hash('xxh128', implode("\0", $fieldNames)),
            hash('xxh128', implode("\0", $classificationInputs)),
        ]);
        if (isset(self::$layouts[$cacheKey])) {
            return self::$layouts[$cacheKey];
        }

        $levels = array_fill_keys(
            $fieldNames,
            $registeredEntityType ? FieldReadLevel::Internal : FieldReadLevel::Public,
        );
        $authorizationInputs = [];
        foreach ($definitions as $name => $definition) {
            $level = self::metadataResolver()->resolve($definition)->level ?? FieldReadLevel::Internal;
            $levels[$name] = $level;
            if ($definition->getSetting('authorizationInput') === true) {
                if ($level !== FieldReadLevel::Protected) {
                    throw new \LogicException(sprintf('Authorization input %s.%s must be Protected.', $entityTypeId, $name));
                }
                $authorizationInputs[] = $name;
            }
        }

        foreach (self::structuralFields($entityKeys) as $field) {
            if (isset($definitions[$field]) && ($levels[$field] ?? null) !== FieldReadLevel::Public) {
                throw new \LogicException(sprintf('Structural field %s.%s must be Public.', $entityTypeId, $field));
            }
            $levels[$field] = FieldReadLevel::Public;
        }
        ksort($levels);
        sort($authorizationInputs);

        return self::$layouts[$cacheKey] = new EntityReadLayout(
            self::generation(),
            $levels,
            $authorizationInputs,
            $registeredEntityType ? FieldReadLevel::Internal : FieldReadLevel::Public,
        );
    }

    /**
     * Merge definition sources without allowing source order to silently
     * choose a field-read classification or authorization-input role.
     *
     * @param array<string, FieldDefinitionInterface> ...$sources
     * @return array<string, FieldDefinitionInterface>
     */
    private static function mergeReadDefinitions(string $entityTypeId, array ...$sources): array
    {
        $merged = [];
        foreach ($sources as $source) {
            foreach ($source as $name => $definition) {
                if (!isset($merged[$name])) {
                    $merged[$name] = $definition;
                    continue;
                }

                $existing = $merged[$name];
                $existingLevel = self::metadataResolver()->resolve($existing)->level;
                $incomingLevel = self::metadataResolver()->resolve($definition)->level;
                $existingAuthorizationInput = $existing->getSetting('authorizationInput') === true;
                $incomingAuthorizationInput = $definition->getSetting('authorizationInput') === true;
                if ($existingLevel !== null && $incomingLevel !== null
                    && ($existingLevel !== $incomingLevel || $existingAuthorizationInput !== $incomingAuthorizationInput)) {
                    throw new \LogicException(sprintf(
                        'Conflicting field-read definitions for %s.%s.',
                        $entityTypeId,
                        $name,
                    ));
                }
                if ($existingLevel === null && $incomingLevel !== null) {
                    $merged[$name] = $definition;
                }
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys
     */
    public static function structureFor(array $values, string $entityTypeId, array $entityKeys, EntityReadLayout $layout): EntityStructure
    {
        $bundleKey = $entityKeys['bundle'] ?? 'bundle';
        $idKey = $entityKeys['id'] ?? 'id';
        $uuidKey = $entityKeys['uuid'] ?? 'uuid';
        $langcodeKey = $entityKeys['langcode'] ?? 'langcode';
        $revisionKey = $entityKeys['revision'] ?? 'revision_id';
        $langcode = (string) ($values[$langcodeKey] ?? 'en');
        $defaultLangcode = (string) ($values['default_langcode'] ?? $langcode);
        $known = array_values(array_unique([$defaultLangcode, $langcode]));
        sort($known);
        $bundle = (string) ($values[$bundleKey] ?? $entityTypeId);
        $bundle = $bundle !== '' ? $bundle : $entityTypeId;

        return new EntityStructure(
            entityTypeId: $entityTypeId,
            bundleId: $bundle,
            id: $values[$idKey] ?? null,
            uuid: isset($values[$uuidKey]) ? (string) $values[$uuidKey] : null,
            activeLanguageId: $langcode,
            defaultLanguageId: $defaultLangcode,
            knownTranslationIds: $known,
            revisionId: $values[$revisionKey] ?? null,
            revisionTip: (bool) ($values['is_latest_revision'] ?? true),
            defaultRevision: (bool) ($values['is_default_revision'] ?? true),
            fieldNames: array_keys($layout->levels()),
        );
    }

    /** @return array{array<string, FieldDefinitionInterface>, bool} */
    private static function classDefinitions(string $class): array
    {
        try {
            $metadata = EntityMetadataReader::forClass($class);
        } catch (EntityMetadataException) {
            return [[], false];
        }

        return [$metadata->fields, $metadata->typeId !== null];
    }

    /** @param array<string, string> $entityKeys @return list<string> */
    private static function structuralFields(array $entityKeys): array
    {
        $fields = [
            $entityKeys['id'] ?? 'id',
            $entityKeys['bundle'] ?? 'bundle',
            $entityKeys['langcode'] ?? 'langcode',
            'default_langcode',
            'is_default_revision',
            'is_latest_revision',
        ];
        foreach (['uuid', 'revision'] as $kind) {
            if (isset($entityKeys[$kind])) {
                $fields[] = $entityKeys[$kind];
            }
        }

        return array_values(array_unique($fields));
    }

    private static function generation(): EntityReadLayoutGeneration
    {
        return self::$generation ??= new EntityReadLayoutGeneration();
    }

    private static function metadataResolver(): FieldReadMetadataResolver
    {
        return self::$metadataResolver ??= new FieldReadMetadataResolver();
    }
}
