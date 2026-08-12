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
final readonly class SchemaMutationCoordinator
{
    public function __construct(
        private Connection $connection,
        private MigrationRepository $repository,
    ) {}

    /**
     * @template T
     * @param callable(): T $transition
     * @return T
     */
    public function execute(callable $transition): mixed
    {
        return $this->connection->transactional(function () use ($transition): mixed {
            $this->repository->acquireSchemaAuthority();
            $this->repository->installOrUpgradeLedger();

            return $transition();
        });
    }
}
