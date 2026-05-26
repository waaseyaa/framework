<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

use Doctrine\DBAL\Connection;

final class DBALTransaction implements TransactionInterface
{
    private bool $active = true;

    public function __construct(
        private readonly Connection $connection,
    ) {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        if (!$this->active) {
            throw new \RuntimeException('Transaction is no longer active.');
        }

        $this->connection->commit();
        $this->active = false;
    }

    public function rollBack(): void
    {
        if (!$this->active) {
            throw new \RuntimeException('Transaction is no longer active.');
        }

        $this->connection->rollBack();
        $this->active = false;
    }
}
