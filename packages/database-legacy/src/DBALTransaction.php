<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

use Doctrine\DBAL\Connection;

final class DBALTransaction implements TransactionCompletionInterface
{
    private bool $active = true;

    private readonly int $frameId;

    public function __construct(
        private readonly Connection $connection,
        private readonly TransactionCompletionCoordinator $completionCoordinator,
    ) {
        $this->frameId = $this->completionCoordinator->begin($this->connection);
    }

    public function commit(): void
    {
        if (!$this->active) {
            throw new \RuntimeException('Transaction is no longer active.');
        }

        $this->connection->commit();
        $this->active = false;
        $this->completionCoordinator->committed($this->frameId);
    }

    public function rollBack(): void
    {
        if (!$this->active) {
            throw new \RuntimeException('Transaction is no longer active.');
        }

        $this->connection->rollBack();
        $this->active = false;
        $this->completionCoordinator->rolledBack($this->frameId);
    }

    public function afterCommit(\Closure $callback): void
    {
        if (!$this->active) {
            throw new \RuntimeException('Transaction is no longer active.');
        }

        $this->completionCoordinator->afterCommit($this->frameId, $callback);
    }
}
