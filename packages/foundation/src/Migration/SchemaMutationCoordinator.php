<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

use Doctrine\DBAL\Connection;

/**
 * The singular transaction boundary for authoritative schema mutation.
 *
 * Writer ownership and ledger installation occur before a transition may
 * inspect or mutate schema state. SQLite DDL, ledger rows, and any data step
 * included by the caller therefore share one DBAL transaction.
 *
 * @api
 */
final class SchemaMutationCoordinator
{
    /** @var \WeakMap<Connection, int>|null */
    private static ?\WeakMap $activeConnections = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationRepository $repository,
    ) {}

    /**
     * @template T
     * @param callable(): T $transition
     * @return T
     */
    public function execute(callable $transition): mixed
    {
        $active = self::$activeConnections ??= new \WeakMap();
        if (($active[$this->connection] ?? 0) > 0) {
            return $transition();
        }

        return $this->connection->transactional(function () use ($transition): mixed {
            $this->repository->acquireSchemaAuthority();
            $this->repository->installOrUpgradeLedger();

            $active = self::$activeConnections ??= new \WeakMap();
            $active[$this->connection] = ($active[$this->connection] ?? 0) + 1;
            try {
                return $transition();
            } finally {
                $depth = $active[$this->connection] - 1;
                if ($depth === 0) {
                    unset($active[$this->connection]);
                } else {
                    $active[$this->connection] = $depth;
                }
            }
        });
    }

    /** Whether this exact connection is already inside the coordinator boundary. */
    public static function isActive(Connection $connection): bool
    {
        return (self::$activeConnections[$connection] ?? 0) > 0;
    }
}
