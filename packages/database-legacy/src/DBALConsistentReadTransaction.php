<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;

/** @internal */
final class DBALConsistentReadTransaction implements TransactionInterface
{
    private bool $active = true;

    private readonly TransactionIsolationLevel $previousIsolation;

    private readonly bool $restoreIsolation;

    public function __construct(
        private readonly Connection $connection,
    ) {
        $this->previousIsolation = $connection->getTransactionIsolation();
        $this->restoreIsolation = $this->previousIsolation !== TransactionIsolationLevel::REPEATABLE_READ;
        if ($connection->isTransactionActive()) {
            throw new \RuntimeException('A consistent read cannot start inside an active transaction.');
        }
        try {
            if ($this->restoreIsolation) {
                $connection->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
            }
            $connection->beginTransaction();
        } catch (\Throwable $exception) {
            try {
                $this->restoreConnectionIsolation();
            } catch (\Throwable) {
                // Doctrine records the requested prior isolation before its
                // platform statement; the connection is discarded below.
            }
            $connection->close();
            throw $exception;
        }
    }

    public function commit(): void
    {
        $this->finish($this->connection->commit(...));
    }

    public function rollBack(): void
    {
        $this->finish($this->connection->rollBack(...));
    }

    private function finish(\Closure $operation): void
    {
        if (!$this->active) {
            throw new \RuntimeException('Transaction is no longer active.');
        }

        try {
            $operation();
        } catch (\Throwable $exception) {
            $this->active = false;
            try {
                $this->restoreConnectionIsolation();
            } catch (\Throwable) {
                // Doctrine records the requested prior isolation before the
                // platform statement; closing discards the uncertain backend.
            }
            $this->connection->close();
            throw $exception;
        }

        $this->active = false;
        try {
            $this->restoreConnectionIsolation();
        } catch (\Throwable $exception) {
            $this->connection->close();
            throw $exception;
        }
    }

    private function restoreConnectionIsolation(): void
    {
        if ($this->restoreIsolation) {
            $this->connection->setTransactionIsolation($this->previousIsolation);
        }
    }
}
