<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\RuntimeState;

/**
 * Privileged atomic activation and restore for prepared SQLite artifacts.
 *
 * The optional activation hook is a test seam. Production callers omit it.
 *
 * @api
 */
final readonly class SqliteArtifactInstaller
{
    /** @param (\Closure(): void)|null $afterActivation */
    public function __construct(
        private SqliteArtifactPreparer $preparer,
        private ?\Closure $afterActivation = null,
    ) {}

    /**
     * @param list<string> $applicationArtifactTables
     */
    public function install(
        string $currentDatabase,
        string $artifactDatabase,
        string $backupDatabase,
        array $applicationArtifactTables,
    ): SqliteArtifactReport {
        $this->assertRegular($currentDatabase, 'Serving database');
        $this->assertRegular($artifactDatabase, 'Artifact database');
        if (file_exists($backupDatabase) || is_link($backupDatabase)) {
            throw new \RuntimeException('Backup database already exists.');
        }

        $this->copyExclusive($currentDatabase, $backupDatabase, $this->mode($currentDatabase));
        if (!hash_equals($this->hash($currentDatabase), $this->hash($backupDatabase))) {
            @unlink($backupDatabase);
            throw new \RuntimeException('The durable database backup differs from the serving database.');
        }

        $candidate = $this->scratchPath($currentDatabase, 'install');
        try {
            $report = $this->preparer->prepare(
                $currentDatabase,
                $artifactDatabase,
                $candidate,
                $applicationArtifactTables,
            );
            chmod($candidate, $this->mode($currentDatabase));
            $this->fsync($candidate);
            $this->activate($candidate, $currentDatabase, expectedHash: null);

            return $report;
        } catch (\Throwable $error) {
            if (file_exists($candidate)) {
                @unlink($candidate);
            }
            throw $error;
        }
    }

    public function restore(string $backupDatabase, string $currentDatabase): void
    {
        $this->assertRegular($backupDatabase, 'Backup database');
        $this->assertRegular($currentDatabase, 'Serving database');
        $candidate = $this->scratchPath($currentDatabase, 'restore');
        try {
            $this->copyExclusive($backupDatabase, $candidate, $this->mode($currentDatabase));
            $this->assertIntegrity($candidate);
            $this->activate($candidate, $currentDatabase, $this->hash($backupDatabase));
        } catch (\Throwable $error) {
            if (file_exists($candidate)) {
                @unlink($candidate);
            }
            throw $error;
        }
    }

    private function activate(string $candidate, string $current, ?string $expectedHash): void
    {
        $previous = $this->scratchPath($current, 'previous');
        if (!rename($current, $previous)) {
            throw new \RuntimeException('Could not preserve the serving database before activation.');
        }

        try {
            if (!rename($candidate, $current)) {
                throw new \RuntimeException('Could not atomically activate the database candidate.');
            }
            ($this->afterActivation ?? static function (): void {})();
            $this->assertIntegrity($current);
            if ($expectedHash !== null && !hash_equals($expectedHash, $this->hash($current))) {
                throw new \RuntimeException('Restored database differs from the verified backup.');
            }
            if (!unlink($previous)) {
                throw new \RuntimeException('Could not remove the superseded database after verification.');
            }
        } catch (\Throwable $error) {
            $failed = $this->scratchPath($current, 'failed');
            if (file_exists($current) && !rename($current, $failed)) {
                throw new \RuntimeException('Activation failed and the failed database could not be moved aside.', 0, $error);
            }
            if (!rename($previous, $current)) {
                throw new \RuntimeException('Activation failed and the serving database could not be restored.', 0, $error);
            }
            $this->fsync($current);
            if (file_exists($failed) && !unlink($failed)) {
                throw new \RuntimeException('Activation rolled back but failed-candidate cleanup failed.', 0, $error);
            }
            throw $error;
        }
    }

    private function copyExclusive(string $source, string $target, int $mode): void
    {
        $input = fopen($source, 'rb');
        $output = fopen($target, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new \RuntimeException('Could not create an exclusive database copy.');
        }
        try {
            if (stream_copy_to_stream($input, $output) === false || !fflush($output)) {
                throw new \RuntimeException('Could not flush the database copy.');
            }
            if (function_exists('fsync') && !fsync($output)) {
                throw new \RuntimeException('Could not fsync the database copy.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        chmod($target, $mode);
    }

    private function assertRegular(string $path, string $label): void
    {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException($label . ' must be a regular non-symlink file.');
        }
    }

    private function assertIntegrity(string $path): void
    {
        $pdo = new \PDO('sqlite:file:' . $path . '?mode=ro&immutable=1', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $integrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity !== 'ok') {
            throw new \RuntimeException('Activated database failed SQLite integrity_check: ' . $integrity);
        }
    }

    private function hash(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new \RuntimeException('Could not hash a database file.');
        }

        return $hash;
    }

    private function fsync(string $path): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Could not open database for fsync.');
        }
        try {
            if (function_exists('fsync') && !fsync($stream)) {
                throw new \RuntimeException('Could not fsync database.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function mode(string $path): int
    {
        clearstatcache(true, $path);
        $permissions = fileperms($path);
        if (!is_int($permissions)) {
            throw new \RuntimeException('Could not read database file permissions.');
        }

        return $permissions & 0o777;
    }

    private function scratchPath(string $current, string $purpose): string
    {
        return dirname($current) . '/.' . basename($current) . '.' . $purpose . '-' . bin2hex(random_bytes(8));
    }
}
