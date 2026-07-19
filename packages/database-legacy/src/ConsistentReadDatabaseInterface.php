<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

/**
 * Database capability for multi-statement reads from one stable snapshot.
 *
 * @api
 */
interface ConsistentReadDatabaseInterface extends DatabaseInterface
{
    public function consistentReadTransaction(string $name = ''): TransactionInterface;
}
