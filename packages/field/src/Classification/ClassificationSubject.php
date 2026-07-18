<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification;

/** Exact immutable classification lifecycle/policy subject. @internal */
final readonly class ClassificationSubject
{
    public function __construct(
        public ?string $label,
        public ?string $inheritedFrom,
        public ?string $overriddenAt,
        public int|string|null $authorId,
    ) {}

    public function carriesStoredValue(): bool
    {
        return $this->label !== null || $this->inheritedFrom !== null || $this->overriddenAt !== null;
    }
}
