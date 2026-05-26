<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

interface TransactionInterface
{
    public function commit(): void;

    public function rollBack(): void;
}
