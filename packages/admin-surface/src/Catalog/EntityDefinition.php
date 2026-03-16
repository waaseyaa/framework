<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Catalog;

/**
 * Fluent builder for defining a single entity type in the admin catalog.
 *
 * Maps to AdminSurfaceCatalogEntry in contract/types.ts.
 */
final class EntityDefinition
{
    private ?string $group = null;

    /** @var FieldDefinition[] */
    private array $fields = [];

    /** @var ActionDefinition[] */
    private array $actions = [];

    private bool $canList = true;
    private bool $canGet = true;
    private bool $canCreate = true;
    private bool $canUpdate = true;
    private bool $canDelete = true;
    private bool $canSchema = true;

    public function __construct(
        private readonly string $id,
        private readonly string $label,
    ) {}

    public function group(string $group): self
    {
        $this->group = $group;
        return $this;
    }

    public function field(string $name, string $label, string $type): FieldDefinition
    {
        $field = new FieldDefinition($name, $label, $type);
        $this->fields[] = $field;
        return $field;
    }

    public function action(string $id, string $label): ActionDefinition
    {
        $action = new ActionDefinition($id, $label);
        $this->actions[] = $action;
        return $action;
    }

    /**
     * Set capabilities. Unmentioned capabilities remain at their defaults (true).
     *
     * @param array<string, bool> $capabilities
     */
    public function capabilities(array $capabilities): self
    {
        foreach ($capabilities as $key => $value) {
            match ($key) {
                'list' => $this->canList = $value,
                'get' => $this->canGet = $value,
                'create' => $this->canCreate = $value,
                'update' => $this->canUpdate = $value,
                'delete' => $this->canDelete = $value,
                'schema' => $this->canSchema = $value,
                default => throw new \InvalidArgumentException("Unknown capability: {$key}"),
            };
        }
        return $this;
    }

    public function readOnly(): self
    {
        $this->canCreate = false;
        $this->canUpdate = false;
        $this->canDelete = false;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'label' => $this->label,
            'group' => $this->group,
            'fields' => array_map(fn(FieldDefinition $f) => $f->toArray(), $this->fields),
            'actions' => array_map(fn(ActionDefinition $a) => $a->toArray(), $this->actions),
            'capabilities' => [
                'list' => $this->canList,
                'get' => $this->canGet,
                'create' => $this->canCreate,
                'update' => $this->canUpdate,
                'delete' => $this->canDelete,
                'schema' => $this->canSchema,
            ],
        ], fn($v) => $v !== null);
    }
}
