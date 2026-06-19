<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Anchor;

use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * The Wayfinding anchor registry: derives the set of valid `data-anchor` IDs
 * from the registered entity types and their schema fields, and validates
 * anchors against that set.
 *
 * This is the server-side counterpart to the inert `data-anchor` attributes the
 * schema-driven admin already emits (Wayfinding Phase-1 groundwork, alpha.227).
 * It produces byte-identical IDs by reading the SAME field set the admin renders
 * (the SchemaPresenter `properties`, minus hidden widgets). It is the source of
 * truth for FR-005 (an emit referencing an anchor not in the catalog is rejected)
 * and FR-007 (the catalog is published on the agent-readable read side).
 *
 * Type-level and static: anchors depend on entity type + field identity, never on
 * a specific record or account, so the catalog is the same for every request.
 *
 * @api
 */
final class AnchorRegistry
{
    private readonly SchemaPresenter $schemaPresenter;

    /** @var array<string, true>|null memoised membership set */
    private ?array $idSet = null;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        ?SchemaPresenter $schemaPresenter = null,
    ) {
        // Nullable + resolve in body: PHP forbids non-trivial expressions as a
        // promoted-property default, and a shared SchemaPresenter is fine here.
        $this->schemaPresenter = $schemaPresenter ?? new SchemaPresenter();
    }

    /**
     * The full anchor catalog across every registered entity type.
     *
     * @return list<Anchor>
     */
    public function catalog(): array
    {
        $anchors = [];

        foreach ($this->entityTypeManager->getDefinitions() as $typeId => $type) {
            // Structural + action anchors mirror SchemaList/SchemaView/SchemaForm
            // containers and their edit/delete/submit actions.
            $anchors[] = Anchor::structural('list', $typeId);
            $anchors[] = Anchor::structural('view', $typeId);
            $anchors[] = Anchor::structural('form', $typeId);
            $anchors[] = Anchor::action($typeId, 'edit');
            $anchors[] = Anchor::action($typeId, 'delete');
            $anchors[] = Anchor::action($typeId, 'submit');

            // Field anchors mirror SchemaList column headers and SchemaView /
            // SchemaForm field rows — keyed on the same non-hidden field set.
            foreach ($this->fieldNames($typeId, $type) as $field) {
                $anchors[] = Anchor::listField($typeId, $field);
                $anchors[] = Anchor::field($typeId, $field);
            }
        }

        return $anchors;
    }

    /**
     * @return list<string> flat list of valid `data-anchor` IDs
     */
    public function anchorIds(): array
    {
        return array_map(static fn(Anchor $a): string => $a->id, $this->catalog());
    }

    /**
     * Whether the given `data-anchor` ID is in the published catalog (FR-005).
     */
    public function isValid(string $anchorId): bool
    {
        if ($this->idSet === null) {
            $this->idSet = [];
            foreach ($this->catalog() as $anchor) {
                $this->idSet[$anchor->id] = true;
            }
        }

        return isset($this->idSet[$anchorId]);
    }

    /**
     * Field machine names the schema-driven admin renders for this type — the
     * SchemaPresenter `properties`, minus hidden-widget fields (matching the
     * SPA's `x-widget !== 'hidden'` filter). Best-effort: a type whose schema
     * cannot be presented contributes no field anchors rather than failing.
     *
     * @return list<string>
     */
    private function fieldNames(string $typeId, EntityTypeInterface $type): array
    {
        try {
            $schema = $this->schemaPresenter->present(
                $type,
                $this->entityTypeManager->resolveFieldDefinitions($typeId),
            );
        } catch (\Throwable) {
            return [];
        }

        $properties = $schema['properties'] ?? null;
        if (!is_array($properties)) {
            return [];
        }

        $fields = [];
        foreach ($properties as $name => $property) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $widget = is_array($property) ? ($property['x-widget'] ?? null) : null;
            if ($widget === 'hidden') {
                continue;
            }
            $fields[] = $name;
        }

        return $fields;
    }
}
