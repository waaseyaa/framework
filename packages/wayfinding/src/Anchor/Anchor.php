<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Anchor;

/**
 * A single entry in the Wayfinding anchor catalog: one valid `data-anchor`
 * target a beacon may anchor to. The `id` is byte-identical to the attribute the
 * schema-driven admin emits (see docs/specs/admin-spa.md "Element anchors").
 *
 * @api
 */
final readonly class Anchor
{
    /**
     * @param non-empty-string $id        The `data-anchor` value (e.g. "field:node:title").
     * @param 'list'|'list-field'|'view'|'field'|'form'|'action' $kind
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $entityType,
        public ?string $field = null,
        public ?string $operation = null,
    ) {}

    /**
     * A container-level anchor for a surface kind (list / view / form).
     *
     * @param 'list'|'view'|'form' $kind
     */
    public static function structural(string $kind, string $entityType): self
    {
        return new self("{$kind}:{$entityType}", $kind, $entityType);
    }

    public static function listField(string $entityType, string $field): self
    {
        return new self("list-field:{$entityType}:{$field}", 'list-field', $entityType, field: $field);
    }

    public static function field(string $entityType, string $field): self
    {
        return new self("field:{$entityType}:{$field}", 'field', $entityType, field: $field);
    }

    public static function action(string $entityType, string $operation): self
    {
        return new self("action:{$entityType}:{$operation}", 'action', $entityType, operation: $operation);
    }

    /**
     * @return array<string, string> stable, JSON-friendly shape for the published catalog
     */
    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'kind' => $this->kind,
            'entity_type' => $this->entityType,
        ];
        if ($this->field !== null) {
            $out['field'] = $this->field;
        }
        if ($this->operation !== null) {
            $out['operation'] = $this->operation;
        }

        return $out;
    }
}
