<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/**
 * Composition-root holder for one repository/driver boundary identity and its
 * four role-separated row/snapshot collaborators.
 *
 * @api
 */
final class StorageBoundary
{
    private readonly StorageBoundaryIdentity $identity;

    public function __construct()
    {
        $this->identity = new StorageBoundaryIdentity();
    }

    public function driverRowFactory(): StorageRowFactory
    {
        return new StorageRowFactory($this->identity);
    }

    public function repositoryRowReader(): StorageRowReader
    {
        return new StorageRowReader($this->identity);
    }

    public function repositorySnapshotFactory(): StorageSnapshotFactory
    {
        return new StorageSnapshotFactory($this->identity);
    }

    public function driverSnapshotReader(): StorageSnapshotReader
    {
        return new StorageSnapshotReader($this->identity);
    }
}
