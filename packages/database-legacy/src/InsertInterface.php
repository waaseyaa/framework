<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

interface InsertInterface
{
    public function fields(array $fields): static;

    public function values(array $values): static;

    public function execute(): int|string;
}
