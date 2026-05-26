<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

interface DeleteInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static;

    public function execute(): int;
}
