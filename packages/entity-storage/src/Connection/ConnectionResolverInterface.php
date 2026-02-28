<?php

declare(strict_types=1);

namespace Aurora\EntityStorage\Connection;

use Aurora\Database\DatabaseInterface;

/**
 * Resolves named database connections.
 *
 * This is the multi-tenancy seam for database access. In single-tenant
 * mode, SingleConnectionResolver always returns the same connection.
 * Multi-tenant implementations can resolve per-tenant connections.
 */
interface ConnectionResolverInterface
{
    /**
     * Returns a database connection by name.
     *
     * @param string|null $name Connection name, or null for the default connection.
     */
    public function connection(?string $name = null): DatabaseInterface;

    /**
     * Returns the default connection name.
     */
    public function getDefaultConnectionName(): string;
}
