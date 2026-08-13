<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Validation;

/** @api */
final readonly class ValidationResult
{
    /** @param list<array{code: string, pointer: string, detail: string}> $violations */
    public function __construct(private array $violations) {}

    /** @return list<array{code: string, pointer: string, detail: string}> */
    public function violations(): array
    {
        return $this->violations;
    }

    public function isValid(): bool
    {
        return [] === $this->violations;
    }
}
