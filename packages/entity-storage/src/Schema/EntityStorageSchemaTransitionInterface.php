<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Schema;

use Waaseyaa\Database\DatabaseInterface;

/**
 * A domain-owned, idempotent data transition inside coordinated schema sync.
 *
 * @internal
 */
interface EntityStorageSchemaTransitionInterface
{
    public function apply(DatabaseInterface $database, string $table): void;
}
