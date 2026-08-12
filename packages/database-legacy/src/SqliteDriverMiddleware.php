<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/** Re-establishes the S1 PRAGMA contract on every physical SQLite connect. */
final class SqliteDriverMiddleware implements Middleware
{
    public function __construct(
        private readonly bool $fileBacked,
    ) {}

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver, $this->fileBacked) extends AbstractDriverMiddleware {
            public function __construct(
                Driver $driver,
                private readonly bool $fileBacked,
            ) {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): DriverConnection
            {
                $connection = parent::connect($params);
                SqliteTopology::configureAndVerifyDriverConnection($connection, $this->fileBacked);

                return $connection;
            }
        };
    }
}
