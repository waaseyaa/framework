<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\Exception\EntityMetadataException;
use Waaseyaa\Entity\Exception\InvalidEntityTypeException;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldStorage;

/**
 * Value object representing an entity type definition.
 *
 * Entity types are registered with the EntityTypeManager and describe
 * the structure and behavior of a class of entities.
 *
 * Content entity types should be built via {@see self::fromClass()}, which
 * reflects on the class's `#[ContentEntityType]`, `#[ContentEntityKeys]`, and
 * `#[Field]` attributes. The constructor's `$_fieldDefinitions` slot is
 * `@internal` and is reserved for that factory plus the test stub helper.
 */
final readonly class EntityType implements EntityTypeInterface, ApiExposableEntityTypeInterface, EntityTypeForeignKeyDefinitionInterface
{
    /**
     * Canonical scope identifier for community-scoped tenancy.
     *
     * The only scope value accepted by {@see self::__construct()} today.
     * Future region/org scopes would extend this surface deliberately.
     */
    public const string TENANCY_SCOPE_COMMUNITY = 'community';

    /**
     * @param string $id Machine name of the entity type (e.g. 'node', 'user').
     * @param string $label Human-readable label.
     * @param class-string<EntityInterface> $class The entity class.
     * @param class-string<Storage\EntityStorageInterface>|string $storageClass The storage handler class.
     * @param array<string, string> $keys Entity keys mapping (id, uuid, label, bundle, revision, langcode).
     * @param bool $revisionable Whether this entity type supports revisions.
     * @param bool $translatable Whether this entity type supports translations.
     * @param string|null $bundleEntityType The entity type ID that provides bundles (e.g. 'node_type' for 'node').
     * @param array<string, mixed> $constraints Validation constraints.
     * @param string|null $description Human-readable description of the entity type.
     * @param string|null $primaryStorageBackend Per-entity-type primary storage backend id.
     *   `null` (default) means the framework default (`sql-blob`) is used by `BackendResolver`.
     *   Override to `'sql-column'` (or another registered backend id) to change the default
     *   for all fields that have not set their own backend via `FieldDefinition::storedIn()`.
     * @param array{scope: string}|null $tenancy Declarative tenancy slot. `null` = non-tenant.
     *   Currently the only accepted shape is `['scope' => 'community']`. Replaces the
     *   legacy `HasCommunityInterface` marker (mission #1257 §C1).
     * @param bool $discoverable Whether this entity type appears in the GET /api discovery
     *   index. Visibility only — CRUD routes and access enforcement are unaffected.
     * @param bool $api Whether generic JSON:API routes are deliberately exposed.
     * @param array<string, FieldDefinitionInterface|array<string, mixed>> $_fieldDefinitions
     *   @internal Field definitions keyed by field name. Populated only by
     *   {@see self::fromClass()} and {@see \Waaseyaa\Entity\Tests\Helper\TestEntityType::stub()}.
     *   Application code MUST NOT pass this argument; doing so is unsupported.
     *
     * @throws \InvalidArgumentException If `$tenancy` is provided and does not match `['scope' => 'community']`.
     * @throws \InvalidArgumentException If `$revisionable` is `true` and `$keys['revision']` is absent or empty.
     */
    public function __construct(
        private string $id,
        private string $label,
        private string $class,
        private string $storageClass = '',
        private array $keys = [],
        private bool $revisionable = false,
        private bool $revisionDefault = false,
        private bool $translatable = false,
        private ?string $bundleEntityType = null,
        private array $constraints = [],
        private ?string $group = null,
        private ?string $description = null,
        private ?string $primaryStorageBackend = null,
        private ?array $tenancy = null,
        private bool $discoverable = true,
        private bool $api = false,
        private array $_fieldDefinitions = [],
        private array $_foreignKeys = [],
    ) {
        // T036: revisionable entity types must declare a non-empty revision key.
        if ($this->revisionable) {
            $revisionKey = $this->keys['revision'] ?? '';
            if ($revisionKey === '') {
                throw new \InvalidArgumentException(\sprintf(
                    'Entity type "%s" declares revisionable=true but entityKeys[\'revision\'] is absent '
                    . 'or empty. Add a revision key, e.g. keys: [\'revision\' => \'vid\'].',
                    $this->id,
                ));
            }
        }

        // FR-005: `translatable` is independent per entity type. A bundleEntityType (e.g.
        // 'node_type' for 'node') does NOT inherit translatability from the content type it
        // provides bundles for. Each entity type declares its own translatable flag.
        if ($this->translatable) {
            if (!isset($this->keys['langcode'])) {
                throw InvalidEntityTypeException::missingLangcodeKey($this->id);
            }
            if (!isset($this->keys['default_langcode'])) {
                throw InvalidEntityTypeException::missingDefaultLangcodeKey($this->id);
            }
            if (!is_subclass_of($this->class, 'Waaseyaa\\Entity\\TranslatableInterface')) {
                throw InvalidEntityTypeException::translatableEntityClassNotImplementingInterface(
                    $this->id,
                    $this->class,
                );
            }
        }

        if ($this->tenancy !== null) {
            $this->validateTenancy($this->tenancy);
        }
    }

    public function getStorageForeignKeys(): array
    {
        return $this->_foreignKeys;
    }

    /**
     * Validate the shape of the `$tenancy` ctor argument.
     *
     * Locked to `['scope' => 'community']` per mission #1257 §C1. Future
     * scopes are an explicit design surface; silent acceptance of unknown
     * scopes would erode the invariant the slot exists to enforce.
     *
     * @param array<string, mixed> $tenancy
     */
    private function validateTenancy(array $tenancy): void
    {
        if (!array_key_exists('scope', $tenancy)) {
            throw new \InvalidArgumentException(
                'EntityType $tenancy must contain a "scope" key (e.g. ["scope" => "community"]); none provided.',
            );
        }

        $extra = array_diff(array_keys($tenancy), ['scope']);
        if ($extra !== []) {
            throw new \InvalidArgumentException(\sprintf(
                'EntityType $tenancy accepts only the "scope" key; unrecognized keys: %s.',
                implode(', ', $extra),
            ));
        }

        if ($tenancy['scope'] !== self::TENANCY_SCOPE_COMMUNITY) {
            throw new \InvalidArgumentException(\sprintf(
                'EntityType $tenancy scope "%s" is not supported; only "%s" is recognized today.',
                (string) $tenancy['scope'],
                self::TENANCY_SCOPE_COMMUNITY,
            ));
        }
    }

    /**
     * @param array{scope: string}|null $tenancy
     */
    private static function describeTenancy(?array $tenancy): string
    {
        if ($tenancy === null) {
            return 'null';
        }
        return \sprintf("['scope' => '%s']", $tenancy['scope']);
    }

    /**
     * Build an EntityType for a content entity class via attribute reflection.
     *
     * Reads:
     *   - #[ContentEntityType(id, label, description)]
     *   - #[ContentEntityKeys(...)]
     *   - #[Field(...)] on each public typed property
     *
     * Pass overrides for any EntityType property that isn't class-derived
     * (e.g. group, storageClass, revisionable, bundleEntityType).
     *
     * Results are cached by class name; repeated calls with the same class
     * return the identical instance (===). Callers that need different
     * overrides per class should not rely on per-call override variation —
     * the framework norm is one canonical EntityType per class.
     *
     * @param class-string<ContentEntityBase> $class
     * @param class-string<Storage\EntityStorageInterface>|string $storageClass
     * @param array<string, mixed> $constraints
     * @throws EntityMetadataException When the class does not declare #[ContentEntityType].
     */
    /**
     * @param array{scope: string}|null $tenancy Forwarded to the constructor; see __construct().
     * @param bool $discoverable Forwarded to the constructor; see __construct(). Like other
     *   non-tenancy overrides it is first-call-wins under the per-class cache.
     *
     * @throws \LogicException When the class has already been resolved with a
     *   different `$tenancy` slot. The cache treats most overrides as
     *   presentation-grade ("framework norm: one canonical EntityType per
     *   class"), but tenancy is a security boundary — silently returning a
     *   cached non-tenant instance to a caller that asked for community
     *   scoping (or vice versa) would disable isolation. Mismatch fails loud.
     */
    public static function fromClass(
        string $class,
        string $storageClass = '',
        bool $revisionable = false,
        bool $revisionDefault = false,
        bool $translatable = false,
        ?string $bundleEntityType = null,
        array $constraints = [],
        ?string $group = null,
        ?array $tenancy = null,
        bool $discoverable = true,
    ): self {
        $cache = &self::fromClassCacheRef();
        if (isset($cache[$class])) {
            $cached = $cache[$class];
            if ($cached->getTenancy() !== $tenancy) {
                throw new \LogicException(\sprintf(
                    'EntityType::fromClass() received a tenancy override for "%s" that conflicts with the '
                    . 'cached instance (cached: %s, requested: %s). Tenancy is a security boundary — '
                    . 'declare it consistently across all fromClass() call sites for the same class, '
                    . 'or call EntityType::clearFromClassCache() between scopes (tests only). '
                    . 'See mission #1257 §C1.',
                    $class,
                    self::describeTenancy($cached->getTenancy()),
                    self::describeTenancy($tenancy),
                ));
            }
            return $cached;
        }

        $metadata = EntityMetadataReader::forClass($class);

        if ($metadata->typeId === null) {
            throw new EntityMetadataException(\sprintf(
                'Class %s must declare #[ContentEntityType] to be used with EntityType::fromClass().',
                $class,
            ));
        }

        $label = $metadata->label !== '' ? $metadata->label : \ucfirst($metadata->typeId);
        $description = $metadata->description !== '' ? $metadata->description : null;

        return $cache[$class] = new self(
            id: $metadata->typeId,
            label: $label,
            class: $class,
            storageClass: $storageClass,
            keys: $metadata->keys,
            revisionable: $revisionable,
            revisionDefault: $revisionDefault,
            translatable: $translatable,
            bundleEntityType: $bundleEntityType,
            constraints: $constraints,
            group: $group,
            description: $description,
            tenancy: $tenancy,
            discoverable: $discoverable,
            api: $metadata->api,
            _fieldDefinitions: $metadata->fields,
        );
    }

    /**
     * Clear the fromClass() instance cache.
     *
     * Intended for tests; production code should not need this.
     */
    public static function clearFromClassCache(): void
    {
        $cache = &self::fromClassCacheRef();
        $cache = [];
    }

    /**
     * Storage for the fromClass() cache.
     *
     * Stored as a function-static instead of a class-static because PHP
     * disallows static properties on `readonly` classes.
     *
     * @return array<class-string, self>
     */
    private static function &fromClassCacheRef(): array
    {
        /** @var array<class-string, self> $cache */
        static $cache = [];

        return $cache;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    /** @return class-string<Storage\EntityStorageInterface> */
    public function getStorageClass(): string
    {
        return $this->storageClass;
    }

    /** @return array<string, string> */
    public function getKeys(): array
    {
        return $this->keys;
    }

    public function isRevisionable(): bool
    {
        return $this->revisionable;
    }

    /**
     * The primary storage backend id configured for this entity type.
     *
     * Returns `null` when none was explicitly set; `BackendResolver` resolves
     * `null` to the framework default (`sql-blob`).
     *
     * @api
     */
    public function getPrimaryStorageBackend(): ?string
    {
        return $this->primaryStorageBackend;
    }

    public function getRevisionDefault(): bool
    {
        return $this->revisionDefault;
    }

    public function isTranslatable(): bool
    {
        return $this->translatable;
    }

    public function getBundleEntityType(): ?string
    {
        return $this->bundleEntityType;
    }

    /** @return array<string, mixed> */
    public function getConstraints(): array
    {
        return $this->constraints;
    }

    /** @return array<string, FieldDefinitionInterface> */
    public function getFieldDefinitions(): array
    {
        $normalized = [];
        foreach ($this->_fieldDefinitions as $name => $definition) {
            if ($definition instanceof FieldDefinitionInterface) {
                if ($definition instanceof FieldDefinition) {
                    $definition->validate($this);
                }
                $normalized[$name] = $definition;
                continue;
            }
            /** @var array<string, mixed> $meta */
            $meta = $definition;
            $settings = $meta['settings'] ?? [];
            if (!is_array($settings)) {
                $settings = [];
            }
            foreach ($meta as $key => $value) {
                if (!in_array($key, ['type', 'label', 'description', 'required', 'readOnly', 'read_only', 'cardinality', 'translatable', 'revisionable', 'default', 'defaultValue', 'settings', 'constraints', 'stored', 'read'], true)) {
                    $settings[$key] = $value;
                }
            }
            $stored = $meta['stored'] ?? FieldStorage::Column;
            if (is_string($stored)) {
                $stored = FieldStorage::tryFrom($stored) ?? FieldStorage::Column;
            }
            if (!$stored instanceof FieldStorage) {
                $stored = FieldStorage::Column;
            }
            $fieldDef = new FieldDefinition(
                name: $name,
                type: (string) ($meta['type'] ?? 'string'),
                cardinality: (int) ($meta['cardinality'] ?? 1),
                settings: $settings,
                targetEntityTypeId: $this->id,
                targetBundle: null,
                translatable: (bool) ($meta['translatable'] ?? false),
                revisionable: (bool) ($meta['revisionable'] ?? false),
                defaultValue: $meta['defaultValue'] ?? ($meta['default'] ?? null),
                label: (string) ($meta['label'] ?? ''),
                description: (string) ($meta['description'] ?? ''),
                required: (bool) ($meta['required'] ?? false),
                readOnly: (bool) ($meta['readOnly'] ?? $meta['read_only'] ?? false),
                constraints: is_array($meta['constraints'] ?? null) ? $meta['constraints'] : [],
                stored: $stored,
                read: ($meta['read'] ?? null) instanceof FieldReadLevel ? $meta['read'] : null,
            );
            $fieldDef->validate($this);
            $normalized[$name] = $fieldDef;
        }

        return $normalized;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getTenancy(): ?array
    {
        return $this->tenancy;
    }

    /**
     * Whether this entity type appears in the GET /api discovery index.
     *
     * Visibility only — CRUD routes and access enforcement are unaffected.
     * Read duck-typed by ApiDiscoveryController (EntityTypeInterface is
     * deliberately not widened; see mission request-surface-hardening research D2).
     */
    public function isDiscoverable(): bool
    {
        return $this->discoverable;
    }

    public function isApiExposed(): bool
    {
        return $this->api;
    }
}
