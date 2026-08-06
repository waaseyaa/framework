<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Database;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Testing\Filesystem\TemporaryDirectory;

/**
 * Owns a file-backed DBAL SQLite database and all of its sidecar files.
 *
 * @api
 */
final class TemporarySqliteDatabase
{
    private readonly TemporaryDirectory $directory;
    private ?DBALDatabase $database = null;
    private readonly string $path;

    public function __construct()
    {
        $this->directory = new TemporaryDirectory('waaseyaa-sqlite-');
        $this->path = $this->directory->path('database.sqlite');
        $this->database = DBALDatabase::createSqlite($this->path);
    }

    public function __destruct()
    {
        $this->remove();
    }

    public function database(): DatabaseInterface
    {
        if ($this->database === null) {
            throw new \LogicException('The temporary database has already been removed.');
        }

        return $this->database;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function remove(): void
    {
        if ($this->database !== null) {
            $this->database->getConnection()->close();
            $this->database = null;
        }
        $this->directory->remove();
    }
}
