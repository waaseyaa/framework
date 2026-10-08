<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\Listing\ListingResolver;

/**
 * Concrete realisation of {@see ListingResolverContract} backed by
 * {@see InMemoryStorageDriver} (no database — pure PHP arrays).
 */
#[CoversClass(ListingResolver::class)]
final class InMemoryListingResolverTest extends ListingResolverContract
{
    protected function createDriver(): EntityStorageDriverInterface
    {
        return new InMemoryStorageDriver();
    }

    protected function createTranslatableDriver(): EntityStorageDriverInterface
    {
        return new InMemoryStorageDriver();
    }

    protected function seed(
        EntityStorageDriverInterface $driver,
        string $entityType,
        array $rows,
    ): void {
        \assert($driver instanceof InMemoryStorageDriver);
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $driver->write($entityType, $id, $row);
        }
    }
}
