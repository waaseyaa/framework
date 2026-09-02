<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintField
{
    /** @param list<string>|null $values */
    public function __construct(
        public string $id,
        public BlueprintFieldType $type,
        public bool $required,
        public int $cardinality,
        public bool $translatable,
        public bool $revisionable,
        public bool $indexed,
        public ?array $values = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'type' => $this->type->value,
            'required' => $this->required,
            'cardinality' => $this->cardinality,
            'translatable' => $this->translatable,
            'revisionable' => $this->revisionable,
            'indexed' => $this->indexed,
        ];
        if ($this->values !== null) {
            $values = $this->values;
            sort($values, SORT_STRING);
            $result['values'] = $values;
        }

        return $result;
    }
}
