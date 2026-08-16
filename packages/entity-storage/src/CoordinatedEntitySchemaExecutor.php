<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

/** Singular coordinator adapter for definition-driven entity schema changes. */
final readonly class CoordinatedEntitySchemaExecutor
{
    public function __construct(private DatabaseInterface $database) {}

    /**
     * @template T
     * @param callable(): T $transition
     * @return T
     */
    public function execute(callable $transition): mixed
    {
        $database = $this->database;
        if (!$database instanceof DBALDatabase) {
            throw new \RuntimeException(
                '[S1-DB107] Entity schema mutation requires the DBAL-backed schema coordinator.',
            );
        }

        $connection = $database->getConnection();

        return new SchemaMutationCoordinator(
            $connection,
            new MigrationRepository($connection),
        )->execute($transition);
    }

    public function isActive(): bool
    {
        $database = $this->database;

        return $database instanceof DBALDatabase
            && SchemaMutationCoordinator::isActive($database->getConnection());
    }
}
