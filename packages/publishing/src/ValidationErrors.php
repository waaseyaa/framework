<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

/**
 * Field-specific validation error collector.
 *
 * Every content-mutation failure surfaces as a list of {field, message}
 * entries so an agent (or form) can attribute each problem to the exact
 * input that caused it — never a single opaque message.
 *
 * @api
 */
final class ValidationErrors
{
    /** @var list<array{field: string, message: string}> */
    private array $errors = [];

    public function add(string $field, string $message): void
    {
        $this->errors[] = ['field' => $field, 'message' => $message];
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /** @return list<array{field: string, message: string}> */
    public function toArray(): array
    {
        return $this->errors;
    }
}
