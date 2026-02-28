<?php

declare(strict_types=1);

namespace Aurora\EntityStorage\Connection;

use Aurora\Database\DatabaseInterface;

/**
 * Single-connection resolver for single-tenant deployments.
 *
 * Always returns the same database connection regardless of the
 * requested connection name. This is the default resolver.
 */
final class SingleConnectionResolver implements ConnectionResolverInterface
{
    private const DEFAULT_CONNECTION_NAME = 'default';

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function connection(?string $name = null): DatabaseInterface
    {
        return $this->database;
    }

    public function getDefaultConnectionName(): string
    {
        return self::DEFAULT_CONNECTION_NAME;
    }
}
