<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/** Repository persistence-extractor role for constructing snapshots. @api */
final readonly class StorageSnapshotFactory
{
    /** @internal Issued by StorageBoundary. */
    public function __construct(private StorageBoundaryIdentity $identity) {}

    /** @param array<string, mixed> $values */
    public function create(array $values): StorageSnapshot
    {
        return StorageSnapshot::forBoundary($values, $this->identity);
    }
}
