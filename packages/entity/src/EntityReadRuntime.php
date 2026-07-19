<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\Exception\EntityMetadataException;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Field\FieldReadLayoutGenerationSourceInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldReadMetadataResolver;

/** Process-wide compiled field-read layout and late-bound guard runtime. @internal */
final class EntityReadRuntime
{
    private const int IMMUTABLE_SEMANTIC_TEMPLATE_LIMIT = 256;

    private static ?EntityReadLayoutGeneration $generation = null;

    private static ?FieldReadMetadataResolver $metadataResolver = null;

    private static ?FieldDefinitionRegistryInterface $fieldRegistry = null;

    private static ?EntityValueReadGuardInterface $guard = null;

    /** @var \WeakMap<FieldDefinitionRegistryInterface, EntityReadLayoutRegistryCacheScope>|null */
    private static ?\WeakMap $registryCacheScopes = null;

    private static ?EntityReadLayoutRegistryCacheScope $noRegistryCacheScope = null;

    /** @var array<string, EntityReadLayoutTemplate> */
    private static array $immutableSemanticTemplates = [];

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
        self::$noRegistryCacheScope?->clear();
        if (self::$registryCacheScopes !== null) {
            foreach (self::$registryCacheScopes as $scope) {
                $scope->clear();
            }
        }
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
        $cacheScope = self::cacheScopeFor($registry);
        [$definitions, $classIsRegistered] = self::classDefinitions($class);
        $registeredEntityType = $registeredEntityType || $classIsRegistered;
        $registryCoreDefinitions = $registry?->coreFieldsFor($entityTypeId) ?? [];
        $registryBundleDefinitions = $registry?->bundleFieldsFor($entityTypeId, $bundle) ?? [];
        $definitionSources = [
            $definitions,
            $entityTypeDefinitions,
            $registryCoreDefinitions,
            $registryBundleDefinitions,
        ];
        $fieldNames = array_values(array_unique(array_merge(
            array_keys($values),
            ...array_map(array_keys(...), $definitionSources),
        )));
        sort($fieldNames);

        $sourceScopeKey = implode("\0", [
            $class,
            $entityTypeId,
            $bundle,
            $registeredEntityType ? 'registered' : 'fixture',
        ]);
        $sourceGeneration = $registry instanceof FieldReadLayoutGenerationSourceInterface
            ? $registry->fieldReadLayoutGeneration($entityTypeId, $bundle)
            : null;
        $sourceCache = $cacheScope->source($sourceScopeKey, $sourceGeneration);
        $sourceCache->synchronizeGeneration();
        $identityFingerprint = self::immutableDefinitionSemanticFingerprint($definitionSources);
        $identityCacheKey = null;
        if ($identityFingerprint !== null) {
            $previousFingerprint = $sourceCache->definitionFingerprint;
            if ($previousFingerprint !== null && $previousFingerprint !== $identityFingerprint) {
                $sourceCache->invalidate();
            }
            $sourceCache->definitionFingerprint = $identityFingerprint;
            $entityKeysForCache = $entityKeys;
            ksort($entityKeysForCache);
            $identityCacheKey = implode("\0", [
                hash('xxh128', implode("\0", $fieldNames)),
                hash('xxh128', json_encode($entityKeysForCache, JSON_THROW_ON_ERROR)),
                $identityFingerprint,
            ]);
            if (isset($sourceCache->identityLayouts[$identityCacheKey])) {
                return $sourceCache->identityLayouts[$identityCacheKey];
            }

            $templateCacheKey = $sourceScopeKey . "\0" . $identityCacheKey;
            $template = self::$immutableSemanticTemplates[$templateCacheKey] ?? null;
            if ($template !== null) {
                return $sourceCache->identityLayouts[$identityCacheKey] = $template->bind(
                    self::generation(),
                    $sourceCache->generation,
                );
            }
        }

        $definitions = self::mergeReadDefinitions($entityTypeId, ...$definitionSources);
        $classificationInputs = [];
        foreach ($definitions as $name => $definition) {
            $level = self::metadataResolver()->resolve($definition)->level ?? FieldReadLevel::Internal;
            $classificationInputs[] = $name . ':' . $level->value . ':'
                . ($definition->getSetting('authorizationInput') === true ? 'auth' : 'ordinary');
        }
        sort($classificationInputs);
        $resolvedFingerprint = hash('xxh128', implode("\0", $classificationInputs));
        if ($identityFingerprint === null) {
            $previousFingerprint = $sourceCache->definitionFingerprint;
            if ($previousFingerprint !== null && $previousFingerprint !== $resolvedFingerprint) {
                $sourceCache->invalidate();
            }
            $sourceCache->definitionFingerprint = $resolvedFingerprint;
        }
        $cacheKey = implode("\0", [
            hash('xxh128', implode("\0", $fieldNames)),
            $resolvedFingerprint,
        ]);
        if (isset($sourceCache->layouts[$cacheKey])) {
            return $sourceCache->layouts[$cacheKey];
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

        $undeclaredLevel = $registeredEntityType ? FieldReadLevel::Internal : FieldReadLevel::Public;
        $template = new EntityReadLayoutTemplate($levels, $authorizationInputs, $undeclaredLevel);
        $layout = $sourceCache->layouts[$cacheKey] = $template->bind(self::generation(), $sourceCache->generation);
        if ($identityCacheKey !== null) {
            $sourceCache->identityLayouts[$identityCacheKey] = $layout;
            self::rememberImmutableSemanticTemplate($sourceScopeKey . "\0" . $identityCacheKey, $template);
        }

        return $layout;
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
     * The direct classification settings are a complete semantic fingerprint
     * only for the framework's final readonly definition value object. Custom
     * definitions retain the full metadata-resolution path on every lookup.
     *
     * @param array<string, FieldDefinitionInterface>[] $sources
     */
    private static function immutableDefinitionSemanticFingerprint(array $sources): ?string
    {
        /** @var array<string, array{level: ?FieldReadLevel, authorizationInput: bool, conflict: string}> $semantics */
        $semantics = [];
        foreach ($sources as $source) {
            ksort($source);
            foreach ($source as $name => $definition) {
                if (!$definition instanceof FieldDefinition) {
                    return null;
                }
                $declaredLevel = $definition->getReadLevel();
                $legacyInternal = $definition->getSetting('internal') === true;
                $readLevel = $declaredLevel ?? ($legacyInternal ? FieldReadLevel::Internal : null);
                $selfConflict = $declaredLevel !== null && $legacyInternal && $declaredLevel !== FieldReadLevel::Internal;
                $incoming = [
                    'level' => $readLevel,
                    'authorizationInput' => $definition->getSetting('authorizationInput') === true,
                    'conflict' => $selfConflict ? 'metadata' : '',
                ];
                $existing = $semantics[$name] ?? null;
                if ($existing === null || $existing['level'] === null) {
                    $semantics[$name] = $incoming;
                    continue;
                }
                if ($incoming['level'] !== null
                    && ($incoming['level'] !== $existing['level']
                        || $incoming['authorizationInput'] !== $existing['authorizationInput'])) {
                    $semantics[$name]['conflict'] = implode('-', [
                        $existing['level']->value,
                        $existing['authorizationInput'] ? 'auth' : 'ordinary',
                        $incoming['level']->value,
                        $incoming['authorizationInput'] ? 'auth' : 'ordinary',
                    ]);
                }
            }
        }

        ksort($semantics);
        $identities = [];
        foreach ($semantics as $name => $semantic) {
            $identities[] = implode(':', [
                $name,
                $semantic['level'] === null ? 'unclassified' : $semantic['level']->value,
                $semantic['authorizationInput'] ? 'auth' : 'ordinary',
                $semantic['conflict'],
            ]);
        }

        return hash('xxh128', implode("\0", $identities));
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

    private static function cacheScopeFor(?FieldDefinitionRegistryInterface $registry): EntityReadLayoutRegistryCacheScope
    {
        if ($registry === null) {
            return self::$noRegistryCacheScope ??= new EntityReadLayoutRegistryCacheScope();
        }

        self::$registryCacheScopes ??= new \WeakMap();
        $scope = self::$registryCacheScopes[$registry] ?? null;
        if ($scope === null) {
            $scope = self::$registryCacheScopes[$registry] = new EntityReadLayoutRegistryCacheScope();
        }

        return $scope;
    }

    private static function metadataResolver(): FieldReadMetadataResolver
    {
        return self::$metadataResolver ??= new FieldReadMetadataResolver();
    }

    private static function rememberImmutableSemanticTemplate(string $key, EntityReadLayoutTemplate $template): void
    {
        if (isset(self::$immutableSemanticTemplates[$key])) {
            return;
        }
        if (count(self::$immutableSemanticTemplates) >= self::IMMUTABLE_SEMANTIC_TEMPLATE_LIMIT) {
            array_shift(self::$immutableSemanticTemplates);
        }
        self::$immutableSemanticTemplates[$key] = $template;
    }
}

/** @internal Immutable classification recipe without registry-generation authority. */
final readonly class EntityReadLayoutTemplate
{
    /**
     * @param array<string, FieldReadLevel> $levels
     * @param list<string> $authorizationInputs
     */
    public function __construct(
        private array $levels,
        private array $authorizationInputs,
        private FieldReadLevel $undeclaredLevel,
    ) {}

    public function bind(
        EntityReadLayoutGeneration $processGeneration,
        EntityReadLayoutGeneration $registryGeneration,
    ): EntityReadLayout {
        return new EntityReadLayout(
            $processGeneration,
            $this->levels,
            $this->authorizationInputs,
            $this->undeclaredLevel,
            [$registryGeneration],
        );
    }
}

/** @internal Mutable cache state isolated to one registry identity. */
final class EntityReadLayoutRegistryCacheScope
{
    /** @var array<string, EntityReadLayoutSourceCache> */
    private array $sources = [];

    public function source(string $key, ?EntityReadLayoutGeneration $generation = null): EntityReadLayoutSourceCache
    {
        $source = $this->sources[$key] ?? null;
        if ($source === null || ($generation !== null && $source->generation !== $generation)) {
            $source = $this->sources[$key] = new EntityReadLayoutSourceCache($generation);
        }

        return $source;
    }

    public function clear(): void
    {
        $this->sources = [];
    }
}

/** @internal Mutable cache state isolated to one definition source. */
final class EntityReadLayoutSourceCache
{
    public readonly EntityReadLayoutGeneration $generation;

    private int $observedGeneration;

    /** @var array<string, EntityReadLayout> */
    public array $layouts = [];

    /** @var array<string, EntityReadLayout> */
    public array $identityLayouts = [];

    public ?string $definitionFingerprint = null;

    public function __construct(?EntityReadLayoutGeneration $generation = null)
    {
        $this->generation = $generation ?? new EntityReadLayoutGeneration();
        $this->observedGeneration = $this->generation->current();
    }

    public function synchronizeGeneration(): void
    {
        $current = $this->generation->current();
        if ($current === $this->observedGeneration) {
            return;
        }
        $this->layouts = [];
        $this->identityLayouts = [];
        $this->definitionFingerprint = null;
        $this->observedGeneration = $current;
    }

    public function invalidate(): void
    {
        $this->generation->advance();
        $this->layouts = [];
        $this->identityLayouts = [];
        $this->definitionFingerprint = null;
        $this->observedGeneration = $this->generation->current();
    }
}
