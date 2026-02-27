<?php

declare(strict_types=1);

namespace Aurora\Database;

final class PdoTransaction implements TransactionInterface
{
    private bool $active = true;

    public function __construct(
        private readonly \PDO $pdo,
    ) {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        if (!$this->active) {
            throw new \RuntimeException('Transaction is no longer active.');
        }

        $this->pdo->commit();
        $this->active = false;
    }

    public function rollBack(): void
    {
        if (!$this->active) {
            throw new \RuntimeException('Transaction is no longer active.');
        }

        $this->pdo->rollBack();
        $this->active = false;
    }
}
