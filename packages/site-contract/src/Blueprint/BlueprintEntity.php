<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintEntity
{
    /** @param array<string, BlueprintField> $fields */
    public function __construct(
        public string $id,
        public string $label,
        public BlueprintStorage $storage,
        public bool $revisionable,
        public bool $translatable,
        public BlueprintEntityKeys $keys,
        public array $fields,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'storage' => $this->storage->value,
            'revisionable' => $this->revisionable,
            'translatable' => $this->translatable,
            'keys' => $this->keys->toArray(),
            'fields' => array_map(static fn(BlueprintField $field): array => $field->toArray(), array_values($this->fields)),
        ];
    }
}
