<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Symfony\Component\Validator\Constraint;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Field\Exception\InvalidFieldDefinitionException;

/**
 * @api
 */
final readonly class FieldDefinition implements FieldDefinitionInterface, \ArrayAccess
{
    /**
     * Field names that are shared across all translations and must never be marked translatable.
     *
     * @api
     */
    public const array SYSTEM_KEYS = ['id', 'uuid', 'langcode', 'default_langcode', 'revision'];

    /**
     * @param array<string, mixed> $settings
     * @param Constraint[] $constraints
     */
    public function __construct(
        private string $name,
        private string $type,
        private int $cardinality = 1,
        private array $settings = [],
        private string $targetEntityTypeId = '',
        private ?string $targetBundle = null,
        private bool $translatable = false,
        private bool $revisionable = false,
        private mixed $defaultValue = null,
        private string $label = '',
        private string $description = '',
        private bool $required = false,
        private bool $readOnly = false,
        private array $constraints = [],
        private FieldStorage $stored = FieldStorage::Column,
        private ?FieldTypeManager $fieldTypeManager = null,
        private string $group = '',
        private array $promptAliases = [],
        private ?string $backendId = null,
        private bool $fieldIndexed = false,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCardinality(): int
    {
        return $this->cardinality;
    }

    public function isMultiple(): bool
    {
        return $this->cardinality !== 1;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getSetting(string $name): mixed
    {
        return $this->settings[$name] ?? null;
    }

    public function getTargetEntityTypeId(): string
    {
        return $this->targetEntityTypeId;
    }

    public function getTargetBundle(): ?string
    {
        return $this->targetBundle;
    }

    public function isTranslatable(): bool
    {
        return $this->translatable;
    }

    public function isRevisionable(): bool
    {
        return $this->revisionable;
    }

    public function getDefaultValue(): mixed
    {
        return $this->defaultValue;
    }

    public function toJsonSchema(): array
    {
        $schema = $this->fieldTypeManager !== null
            ? $this->fieldTypeManager->jsonSchemaFor($this)
            : $this->legacyJsonSchema();

        if ($this->isMultiple()) {
            return [
                'type' => 'array',
                'items' => $schema,
            ];
        }

        return $schema;
    }

    /**
     * Fallback JSON Schema mapping used when no FieldTypeManager has been
     * threaded through construction.
     *
     * Mirrors AbstractFieldType::jsonSchemaFor() exactly so manager-less
     * construction (unit tests, ad-hoc callers) and manager-driven
     * construction emit bit-identical output for every existing field type.
     * EnumItem (WP02) only takes effect when a manager is present.
     */
    private function legacyJsonSchema(): array
    {
        return match ($this->type) {
            'string' => ['type' => 'string'],
            'integer' => ['type' => 'integer'],
            'boolean' => ['type' => 'boolean'],
            'float' => ['type' => 'number'],
            'text' => [
                'type' => 'object',
                'properties' => [
                    'value' => ['type' => 'string'],
                    'format' => ['type' => 'string'],
                ],
            ],
            'entity_reference' => [
                'type' => 'object',
                'properties' => [
                    'target_id' => ['type' => 'integer'],
                    'target_type' => ['type' => 'string'],
                ],
            ],
            default => ['type' => 'string'],
        };
    }

    // DataDefinitionInterface methods

    public function getDataType(): string
    {
        return $this->type;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    /** @return list<string> */
    public function getPromptAliases(): array
    {
        return $this->promptAliases;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function isList(): bool
    {
        return $this->isMultiple();
    }

    /** @return Constraint[] */
    public function getConstraints(): array
    {
        return $this->constraints;
    }

    public function getStored(): FieldStorage
    {
        return $this->stored;
    }

    /**
     * Return a new instance pinned to the given storage backend id.
     *
     * At boot, {@see \Waaseyaa\EntityStorage\Backend\BackendRegistrar} validates
     * the id against the registered set and raises {@see \InvalidArgumentException}
     * for unknown ids. The method itself accepts any string — validation is
     * deferred to registration time so definition objects can be constructed
     * before backends are registered.
     *
     * @api
     * @see \Waaseyaa\EntityStorage\Backend\BackendRegistrar::validateFieldBackendIds()
     */
    public function storedIn(string $backendId): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            cardinality: $this->cardinality,
            settings: $this->settings,
            targetEntityTypeId: $this->targetEntityTypeId,
            targetBundle: $this->targetBundle,
            translatable: $this->translatable,
            revisionable: $this->revisionable,
            defaultValue: $this->defaultValue,
            label: $this->label,
            description: $this->description,
            required: $this->required,
            readOnly: $this->readOnly,
            constraints: $this->constraints,
            stored: $this->stored,
            fieldTypeManager: $this->fieldTypeManager,
            group: $this->group,
            promptAliases: $this->promptAliases,
            backendId: $backendId,
            fieldIndexed: $this->fieldIndexed,
        );
    }

    /**
     * Return the backend id set via {@see storedIn()}, or null if none was set.
     *
     * @api
     */
    public function getBackendId(): ?string
    {
        return $this->backendId;
    }

    /**
     * Return a new instance marked for B-tree indexing under the sql-column backend.
     *
     * Has no effect if the field is not routed to the sql-column backend.
     *
     * @api
     */
    public function indexed(): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            cardinality: $this->cardinality,
            settings: $this->settings,
            targetEntityTypeId: $this->targetEntityTypeId,
            targetBundle: $this->targetBundle,
            translatable: $this->translatable,
            revisionable: $this->revisionable,
            defaultValue: $this->defaultValue,
            label: $this->label,
            description: $this->description,
            required: $this->required,
            readOnly: $this->readOnly,
            constraints: $this->constraints,
            stored: $this->stored,
            fieldTypeManager: $this->fieldTypeManager,
            group: $this->group,
            promptAliases: $this->promptAliases,
            backendId: $this->backendId,
            fieldIndexed: true,
        );
    }

    /**
     * Return whether this field is marked for B-tree indexing.
     *
     * @api
     */
    public function isIndexed(): bool
    {
        return $this->fieldIndexed;
    }

    /**
     * Return a new instance with the translatable flag set to the given value.
     *
     * Defaults to true so callers can write `->translatable()` without an argument.
     *
     * @api
     */
    public function translatable(bool $value = true): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            cardinality: $this->cardinality,
            settings: $this->settings,
            targetEntityTypeId: $this->targetEntityTypeId,
            targetBundle: $this->targetBundle,
            translatable: $value,
            revisionable: $this->revisionable,
            defaultValue: $this->defaultValue,
            label: $this->label,
            description: $this->description,
            required: $this->required,
            readOnly: $this->readOnly,
            constraints: $this->constraints,
            stored: $this->stored,
            fieldTypeManager: $this->fieldTypeManager,
            group: $this->group,
            promptAliases: $this->promptAliases,
            backendId: $this->backendId,
            fieldIndexed: $this->fieldIndexed,
        );
    }

    /**
     * Validate this field definition against the given entity type.
     *
     * Called at boot when field definitions are registered on an entity type.
     * Enforces FR-016 (translatable field on non-translatable entity type) and
     * FR-017 (system key fields must not be translatable).
     *
     * @throws InvalidFieldDefinitionException If a structural invariant is violated.
     * @api
     */
    public function validate(EntityTypeInterface $entityType): void
    {
        if ($this->translatable && in_array($this->name, self::SYSTEM_KEYS, true)) {
            throw InvalidFieldDefinitionException::systemKeyMarkedTranslatable($this->name);
        }

        if ($this->translatable && !$entityType->isTranslatable()) {
            throw InvalidFieldDefinitionException::translatableOnNonTranslatableEntityType(
                $this->name,
                $entityType->id(),
            );
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        if (!is_string($offset)) {
            return false;
        }

        return match ($offset) {
            'settings' => $this->settings !== [],
            'target_entity_type_id', 'targetEntityTypeId' => isset($this->settings['target_entity_type_id']) || isset($this->settings['targetEntityTypeId']),
            'default', 'defaultValue' => $this->defaultValue !== null,
            'name', 'type', 'cardinality', 'target_bundle', 'targetBundle', 'translatable', 'revisionable', 'label', 'description', 'required', 'readOnly', 'read_only', 'stored' => true,
            default => array_key_exists($offset, $this->settings),
        };
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!is_string($offset)) {
            return null;
        }

        return match ($offset) {
            'name' => $this->name,
            'type' => $this->type,
            'cardinality' => $this->cardinality,
            'settings' => $this->settings,
            'target_entity_type_id', 'targetEntityTypeId' => $this->settings['target_entity_type_id'] ?? $this->settings['targetEntityTypeId'] ?? '',
            'target_bundle', 'targetBundle' => $this->targetBundle,
            'translatable' => $this->translatable,
            'revisionable' => $this->revisionable,
            'default', 'defaultValue' => $this->defaultValue,
            'label' => $this->label,
            'description' => $this->description,
            'required' => $this->required,
            'readOnly', 'read_only' => $this->readOnly,
            'stored' => $this->stored,
            default => $this->settings[$offset] ?? null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('FieldDefinition is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('FieldDefinition is immutable.');
    }
}
