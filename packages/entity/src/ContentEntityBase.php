<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Entity\Attribute\EntityMetadataReader;
use Waaseyaa\Entity\Exception\EntityMetadataException;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Hydration\HydratableFromStorageInterface;
use Waaseyaa\Entity\Hydration\HydrationContext;
use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * Abstract base class for content entities (nodes, users, terms, etc.).
 *
 * Content entities are fieldable: they support dynamic fields that can be
 * added and removed through configuration. Field values live in the values array
 * in storage-canonical form; optional {@see EntityBase::$casts} on subclasses make
 * {@see get()} / {@see set()} cast-aware. Entity values are read/written as plain
 * $values via EntityBase::get()/set() (with optional ValueCaster); there is no field
 * item-object layer in this value path (the dead Drupal-lineage FieldItemList/
 * FieldItemBase object layer was removed in audit C-24).
 *
 * Unlike Drupal, a ContentEntityBase object represents ONE language at a time.
 * getTranslation() returns a separate entity object for the requested language.
 *
 * @phpstan-consistent-constructor
 */
abstract class ContentEntityBase extends EntityBase implements ContentEntityInterface, HydratableFromStorageInterface, TranslatableInterface, RevisionableEntityInterface
{
    use TranslatableEntityTrait;

    // Revision-capability for content entities. The methods are inert for
    // non-revisionable types (revisionId() stays null, isCurrentRevision()
    // stays true, revisionMetadata() stays null) — actual revision history is
    // only created when the EntityType is registered with revisionable: true.
    // The trait carries the standard revision-metadata slot (author, timestamp,
    // log) and the storage layer hydrates it via the set* helpers.
    use RevisionableEntityTrait;

    /**
     * Process-wide field registry consulted by {@see getFieldDefinitions()}.
     *
     * AbstractKernel wires this at boot with the same FieldDefinitionRegistry
     * held by EntityTypeManager. When set, getFieldDefinitions() returns the
     * bundle-aware union per docs/specs/bundle-scoped-fields.md §Resolution;
     * when null, it returns the per-instance legacy array. Tests that wire
     * this must reset it in tearDown() to avoid bleed between cases.
     */
    private static ?FieldDefinitionRegistryInterface $fieldRegistry = null;

    /**
     * Process-wide EntityTypeManager consulted by {@see getEntityType()}.
     *
     * AbstractKernel wires this at boot. When null, getEntityType() falls back to
     * constructing a minimal EntityType from the entity's own metadata (translatable: false).
     * Tests that wire this must reset it in tearDown() to avoid bleed between cases.
     */
    private static ?EntityTypeManager $entityTypeManager = null;

    /**
     * Field definitions passed into the entity constructor (legacy path).
     *
     * @var array<string, FieldDefinitionInterface>
     */
    protected array $fieldDefinitions = [];

    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param string $entityTypeId The entity type machine name.
     * @param array<string, string> $entityKeys Entity key mappings.
     * @param array<string, FieldDefinitionInterface> $fieldDefinitions Field definitions keyed by field name.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        if ($entityTypeId === '' || $entityKeys === []) {
            $meta = EntityMetadataReader::forClass(static::class);
            if ($entityTypeId === '') {
                $entityTypeId = $meta->typeId ?? '';
            }
            if ($entityKeys === []) {
                $entityKeys = $meta->keys;
            }
        }

        if ($entityTypeId === '') {
            throw new EntityMetadataException(\sprintf(
                'Concrete content entity %s must declare #[ContentEntityType(id: "…")].',
                static::class,
            ));
        }

        parent::__construct($values, $entityTypeId, $entityKeys);
        $this->fieldDefinitions = $fieldDefinitions;
    }

    public static function setFieldRegistry(?FieldDefinitionRegistryInterface $registry): void
    {
        self::$fieldRegistry = $registry;
    }

    /**
     * Wires the process-wide EntityTypeManager used by {@see getEntityType()}.
     *
     * Called by AbstractKernel at boot alongside {@see setFieldRegistry()}.
     * Pass null to reset (e.g., in test tearDown()).
     */
    public static function setEntityTypeManager(?EntityTypeManager $manager): void
    {
        self::$entityTypeManager = $manager;
    }

    /**
     * Returns the EntityTypeInterface definition for this entity.
     *
     * Satisfies the abstract contract declared in {@see TranslatableEntityTrait}.
     * When the process-wide EntityTypeManager is not wired (e.g., isolated unit tests),
     * this falls back to a minimal EntityType built from the entity's own metadata.
     */
    public function getEntityType(): EntityTypeInterface
    {
        if (self::$entityTypeManager !== null && self::$entityTypeManager->hasDefinition($this->entityTypeId)) {
            return self::$entityTypeManager->getDefinition($this->entityTypeId);
        }

        // Fallback for tests and environments where the manager is not wired.
        // Non-translatable by default — callers can override by wiring the manager.
        return new EntityType(
            id: $this->entityTypeId,
            label: $this->entityTypeId,
            class: static::class,
        );
    }

    public function hasField(string $name): bool
    {
        return \in_array($name, $this->fieldNames(), true)
            || \array_key_exists($name, $this->getFieldDefinitions());
    }

    /** @return array<string, FieldDefinitionInterface> */
    public function getFieldDefinitions(): array
    {
        if (self::$fieldRegistry === null) {
            return $this->fieldDefinitions;
        }

        $core = self::$fieldRegistry->coreFieldsFor($this->entityTypeId);
        $bundle = self::$fieldRegistry->bundleFieldsFor($this->entityTypeId, $this->bundle());

        if ($core === [] && $bundle === []) {
            return $this->fieldDefinitions;
        }

        return $core + $bundle;
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function duplicateInstance(array $values): static
    {
        $class = static::class;

        return new $class($values, $this->entityTypeId, $this->entityKeys, $this->fieldDefinitions);
    }

    public static function fromStorage(array $values, HydrationContext $context): static
    {
        return new static(
            values: $values,
            entityTypeId: $context->entityTypeId,
            entityKeys: $context->entityKeys,
            fieldDefinitions: [],
        );
    }
}
