<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/** Repository-only role for hydrating opaque rows. @api */
final readonly class StorageRowReader
{
    /** @internal Issued by StorageBoundary. */
    public function __construct(private StorageBoundaryIdentity $identity) {}

    /** @return array<string, mixed> */
    public function read(StorageRow $row): array
    {
        return $row->valuesForBoundary($this->identity);
    }

    /** @return array<int|string, array<string, mixed>> */
    public function readSet(StorageRowSet $rows): array
    {
        $values = [];
        foreach ($rows->rowsForBoundary() as $key => $row) {
            $values[$key] = $this->read($row);
        }

        return $values;
    }
}
