<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/** Driver-only role for consuming a persistence snapshot. @api */
final readonly class StorageSnapshotReader
{
    /** @internal Issued by StorageBoundary. */
    public function __construct(private StorageBoundaryIdentity $identity) {}

    /** @return array<string, mixed> */
    public function read(StorageSnapshot $snapshot): array
    {
        return $snapshot->valuesForBoundary($this->identity);
    }
}
