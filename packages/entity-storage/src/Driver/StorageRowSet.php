<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/**
 * Opaque collection of rows. It exposes row objects, never their value bags.
 *
 * @api
 */
final class StorageRowSet implements \Countable
{
    /** @var array<int|string, StorageRow> */
    private readonly array $rows;

    /** @param array<int|string, mixed> $rows */
    public function __construct(array $rows = [])
    {
        $validated = [];
        foreach ($rows as $key => $row) {
            if (!$row instanceof StorageRow) {
                throw new \InvalidArgumentException('StorageRowSet accepts only StorageRow objects.');
            }
            $validated[$key] = $row;
        }
        $this->rows = $validated;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function row(int|string $key): ?StorageRow
    {
        return $this->rows[$key] ?? null;
    }

    /** @internal StorageRowReader seam. @return array<int|string, StorageRow> */
    public function rowsForBoundary(): array
    {
        return $this->rows;
    }

    public function __serialize(): array
    {
        throw new \LogicException('Storage row sets cannot be serialized.');
    }
}
