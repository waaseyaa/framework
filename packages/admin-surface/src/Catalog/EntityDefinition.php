<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Catalog;

/**
 * Fluent builder for defining a single entity type in the admin catalog.
 *
 * Maps to AdminSurfaceCatalogEntry in contract/types.ts.
 * @api
 */
final class EntityDefinition
{
    private ?string $group = null;
    private ?string $description = null;

    /** @var FieldDefinition[] */
    private array $fields = [];

    /** @var ActionDefinition[] */
    private array $actions = [];

    /**
     * @var array{
     *   labelField: string,
     *   search: array{field: string, operator: 'STARTS_WITH'|'CONTAINS'}|null,
     *   sort: array{field: string, direction: 'ASC'}|null
     * }|null
     */
    private ?array $reference = null;

    private bool $canList = true;
    private bool $canGet = true;
    private bool $canCreate = true;
    private bool $canUpdate = true;
    private bool $canDelete = true;
    private bool $canSchema = true;

    /** Fail-closed: history is advertised only when a host declares revisions. */
    private bool $canRevisions = false;

    public function __construct(
        private readonly string $id,
        private readonly string $label,
    ) {}

    public function group(string $group): self
    {
        $this->group = $group;
        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;
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
     * Declare authoritative display and query fields for entity references.
     *
     * A null search or sort field explicitly means that operation is not
     * available. Callers must not invent a fallback field or unfiltered list.
     */
    public function reference(
        string $labelField,
        ?string $searchField = null,
        ?string $sortField = null,
        string $searchOperator = 'STARTS_WITH',
    ): self {
        foreach (array_filter([$labelField, $searchField, $sortField], static fn(?string $field): bool => $field !== null) as $field) {
            if ($field === '' || preg_match('/^[A-Za-z0-9_]+$/', $field) !== 1) {
                throw new \InvalidArgumentException('Reference metadata fields must be non-empty machine names.');
            }
        }
        if (!in_array($searchOperator, ['STARTS_WITH', 'CONTAINS'], true)) {
            throw new \InvalidArgumentException('Reference search operator must be STARTS_WITH or CONTAINS.');
        }

        $this->reference = [
            'labelField' => $labelField,
            'search' => $searchField === null ? null : [
                'field' => $searchField,
                'operator' => $searchOperator,
            ],
            'sort' => $sortField === null ? null : [
                'field' => $sortField,
                'direction' => 'ASC',
            ],
        ];

        return $this;
    }

    /**
     * Set capabilities. Unmentioned capabilities remain at their defaults: true,
     * except `revisions`, which is false until a host declares it.
     *
     * A type that keeps no revisions has no history surface at all, and the
     * history endpoint answers 404 rather than an empty list. Advertising the
     * affordance by default would put every client one click from a refusal, so
     * this one capability is fail-closed: a host that knows a type is
     * revisionable says so.
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
                'revisions' => $this->canRevisions = $value,
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
            'description' => $this->description,
            'group' => $this->group,
            'reference' => $this->reference,
            'fields' => array_map(fn(FieldDefinition $f) => $f->toArray(), $this->fields),
            'actions' => array_map(fn(ActionDefinition $a) => $a->toArray(), $this->actions),
            'capabilities' => [
                'list' => $this->canList,
                'get' => $this->canGet,
                'create' => $this->canCreate,
                'update' => $this->canUpdate,
                'delete' => $this->canDelete,
                'schema' => $this->canSchema,
                'revisions' => $this->canRevisions,
            ],
        ], fn($v) => $v !== null);
    }
}
