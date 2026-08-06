<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Traits;

/**
 * Trait that manages database state between tests.
 *
 * Wraps each test in a transaction that is rolled back after the
 * test completes, ensuring a clean database for every test.
 *
 * Requires a PDO connection to be provided via the getDatabasePdo()
 * method. Override this method in your test class to return the
 * PDO instance used by your test infrastructure.
 *
 * @api
 * @deprecated since 0.1.0-alpha.288. Use TemporarySqliteDatabase and the
 *   framework DatabaseInterface instead of exposing raw PDO to tests.
 */
trait RefreshDatabase
{
    /**
     * Whether a transaction is currently active for the test.
     */
    private bool $databaseTransactionActive = false;

    /**
     * Begin a database transaction before the test.
     *
     * Call this from setUp() after your database is ready.
     */
    protected function beginDatabaseTransaction(): void
    {
        $pdo = $this->getDatabasePdo();

        if ($pdo !== null && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $this->databaseTransactionActive = true;
        }
    }

    /**
     * Roll back the database transaction after the test.
     *
     * Call this from tearDown() to ensure a clean state.
     */
    protected function rollBackDatabaseTransaction(): void
    {
        if (!$this->databaseTransactionActive) {
            return;
        }

        $pdo = $this->getDatabasePdo();

        if ($pdo !== null && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $this->databaseTransactionActive = false;
    }

    /**
     * Run a schema migration callback within the current database.
     *
     * Useful for setting up tables needed by a test. The callback
     * receives the PDO instance.
     *
     * @param callable(\PDO): void $migration
     */
    protected function migrate(callable $migration): void
    {
        $pdo = $this->getDatabasePdo();

        if ($pdo !== null) {
            $migration($pdo);
        }
    }

    /**
     * Truncate all rows from the given tables.
     *
     * @param string[] $tables
     */
    protected function truncateTables(array $tables): void
    {
        $pdo = $this->getDatabasePdo();

        if ($pdo === null) {
            return;
        }

        foreach ($tables as $table) {
            $stmt = $pdo->prepare('DELETE FROM ' . $table);
            $stmt->execute();
        }
    }

    /**
     * Return the PDO connection used for database operations.
     *
     * Override this method to return the PDO instance from your
     * test's database setup. Return null if no database is configured.
     */
    abstract protected function getDatabasePdo(): ?\PDO;
}
