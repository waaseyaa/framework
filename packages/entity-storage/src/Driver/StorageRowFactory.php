<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/** Driver-only role for constructing backend rows. @api */
final readonly class StorageRowFactory
{
    /** @internal Issued by StorageBoundary. */
    public function __construct(private StorageBoundaryIdentity $identity) {}

    /** @param array<string, mixed> $values */
    public function create(array $values): StorageRow
    {
        return StorageRow::forBoundary($values, $this->identity);
    }
}
