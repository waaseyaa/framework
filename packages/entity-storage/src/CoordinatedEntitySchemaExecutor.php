<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Doctrine\DBAL\Exception\ReadOnlyException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

/** Singular coordinator adapter for definition-driven entity schema changes. */
final class CoordinatedEntitySchemaExecutor
{
    /** @var \WeakMap<\Doctrine\DBAL\Connection, true>|null */
    private static ?\WeakMap $planningConnections = null;

    public function __construct(private DatabaseInterface $database) {}

    /**
     * @template T
     * @param callable(): T $transition
     * @return T
     */
    public function execute(callable $transition): mixed
    {
        $database = $this->database;
        if (!$database instanceof DBALDatabase) {
            throw new \RuntimeException(
                '[S1-DB107] Entity schema mutation requires the DBAL-backed schema coordinator.',
            );
        }

        $connection = $database->getConnection();

        return new SchemaMutationCoordinator(
            $connection,
            new MigrationRepository($connection),
        )->execute($transition);
    }

    /**
     * Inspect an SQLite schema transition while the connection is query-only.
     *
     * The transition runs through the same ensure paths as the apply phase,
     * but SQLite refuses the first attempted DDL/DML before it can mutate the
     * database. Completing without that refusal proves the transition is a
     * no-op and therefore does not need schema authority.
     */
    public function requiresMutation(callable $transition): bool
    {
        $database = $this->database;
        if (!$database instanceof DBALDatabase) {
            return true;
        }

        $connection = $database->getConnection();
        if (SchemaMutationCoordinator::isActive($connection)) {
            return true;
        }
        if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            return true;
        }

        $wasQueryOnly = (int) $connection->fetchOne('PRAGMA query_only') === 1;
        if (!$wasQueryOnly) {
            $connection->executeStatement('PRAGMA query_only = ON');
        }

        $planning = self::$planningConnections ??= new \WeakMap();
        $planning[$connection] = true;
        try {
            $transition();

            return false;
        } catch (ReadOnlyException) {
            return true;
        } finally {
            unset($planning[$connection]);
            if (!$wasQueryOnly) {
                $connection->executeStatement('PRAGMA query_only = OFF');
            }
        }
    }

    /**
     * Whether {@see requiresMutation()}'s answer is a genuine read-only
     * determination rather than the conservative "assume mutation" fallback.
     *
     * The query-only trick {@see requiresMutation()} relies on only exists for
     * SQLite, and only when no mutation is already in flight on this
     * connection. Off SQLite (MySQL/MariaDB/PostgreSQL in production), or
     * while a mutation coordinator is already active, `requiresMutation()`
     * unconditionally returns `true` — that is a safe default for deciding
     * *whether* to run the singular mutation coordinator, but callers that
     * want to *report* the outcome (e.g. `schema:sync --dry-run`) must not
     * present that fallback as a confirmed "this table will be altered": it
     * is genuinely unknown until the mutation is applied. (#2732)
     */
    public function canPreviewMutation(): bool
    {
        $database = $this->database;
        if (!$database instanceof DBALDatabase) {
            return false;
        }

        $connection = $database->getConnection();
        if (SchemaMutationCoordinator::isActive($connection)) {
            return false;
        }

        return $connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    public function isActive(): bool
    {
        $database = $this->database;

        return $database instanceof DBALDatabase
            && (
                isset(self::$planningConnections[$database->getConnection()])
                || SchemaMutationCoordinator::isActive($database->getConnection())
            );
    }
}
