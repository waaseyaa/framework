<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/**
 * Opaque backend row. Drivers may construct it; only a future repository-owned
 * hydrator capability may unwrap it.
 *
 * @api
 */
final class StorageRow
{
    /** @param array<string, mixed> $values */
    private function __construct(
        private readonly array $values,
        private readonly StorageBoundaryIdentity $identity,
    ) {}

    /** @internal StorageBoundary construction seam. @param array<string, mixed> $values */
    public static function forBoundary(array $values, StorageBoundaryIdentity $identity): self
    {
        return new self($values, $identity);
    }

    /**
     * @internal StorageRowReader seam.
     * @return array<string, mixed>
     */
    public function valuesForBoundary(StorageBoundaryIdentity $identity): array
    {
        if ($identity !== $this->identity) {
            throw new \LogicException('A repository reader for this storage boundary is required.');
        }

        return $this->values;
    }

    public function __serialize(): array
    {
        throw new \LogicException('Storage rows cannot be serialized.');
    }
}
