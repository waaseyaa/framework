<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Runner;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Migration\Exception\MigrationConcurrencyException;

/**
 * Per-migration filesystem advisory lock.
 *
 * One {@see MigrationLock} instance gates one `(<migration-id>)` pair. The
 * lock file lives at `<lockDir>/<migration-id>.lock` and is held via
 * `flock($handle, LOCK_EX | LOCK_NB)`. Inside the handle the holder writes
 * its `getmypid()` so operators inspecting a stale lock can see who owns
 * it.
 *
 * Acquisition is **non-blocking**. A failed acquire raises
 * {@see MigrationConcurrencyException} — blocking acquisition would create
 * silent multi-minute waits in CI that confuse operators (see reviewer
 * guidance on this WP).
 *
 * Release is best-effort and idempotent:
 *
 *  - explicit `release()` is called from the `finally` block of every
 *    `import:*` command;
 *  - `pcntl_signal()` handlers (SIGTERM / SIGINT) call `release()` when
 *    the extension is available;
 *  - `register_shutdown_function()` calls `release()` on script teardown
 *    for SAPIs that lack pcntl (e.g. Windows).
 *
 * Stale locks (process died without releasing) are NOT auto-removed.
 * Per spec §9.3 (decision D11), PID reuse on long-running hosts would make
 * accidental concurrent runs silent — operators must remove the file by
 * hand. {@see MigrationConcurrencyException}'s message documents the
 * recovery command.
 *
 * Thread-safety: this class is single-process. Multiple instances against
 * the same migration id in the same PHP process are not supported — the
 * second acquire will succeed against the kernel's own lock entry. Tests
 * exercising contention use `pcntl_fork()` to put the two instances in
 * separate processes.
 *
 * @api
 *
 * @spec FR-061 — per-migration concurrency lock
 * @spec FR-062 — graceful release on SIGTERM/SIGINT
 */
final class MigrationLock
{
    /** Migration id pattern — defensive; same shape as MigrationDefinition. */
    private const string MIGRATION_ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** Open file handle once the lock is held, or null when released. */
    private mixed $handle = null;

    /** PID written by this instance while it actively holds the lock. */
    private ?int $heldPid = null;

    /** Absolute path to the lock file derived from `$lockDir` + `$migrationId`. */
    private readonly string $lockPath;

    /** Set true when {@see installSignalHandlers()} / shutdown have wired releases. */
    private bool $handlersInstalled = false;

    /**
     * @param string $migrationId Migration id to lock. Must match `/^[a-z][a-z0-9_]*$/`.
     * @param string $lockDir Absolute path to the lock directory (typically `<storage_root>/migration-locks`). Created on demand.
     * @param LoggerInterface|null $logger Optional logger; falls back to silent.
     *
     * @throws \InvalidArgumentException When `$migrationId` is empty or malformed, or `$lockDir` is empty.
     */
    public function __construct(
        public readonly string $migrationId,
        private readonly string $lockDir,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if ($migrationId === '') {
            throw new \InvalidArgumentException(
                'MigrationLock::__construct(): $migrationId must be a non-empty string.',
            );
        }
        if (\preg_match(self::MIGRATION_ID_PATTERN, $migrationId) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'MigrationLock::__construct(): $migrationId %s does not match %s.',
                \var_export($migrationId, true),
                self::MIGRATION_ID_PATTERN,
            ));
        }
        if ($lockDir === '') {
            throw new \InvalidArgumentException(
                'MigrationLock::__construct(): $lockDir must be a non-empty string.',
            );
        }

        $this->lockPath = \rtrim($lockDir, '/\\') . \DIRECTORY_SEPARATOR . $migrationId . '.lock';
    }

    /**
     * Return the absolute lock-file path. Stable for the lifetime of the instance.
     *
     * @api
     */
    public function lockPath(): string
    {
        return $this->lockPath;
    }

    /**
     * Read the PID currently recorded in the lock file, or `null` when no
     * lock file exists / the file is empty / the body is not numeric.
     *
     * Used by callers building human-readable diagnostics and by the
     * exception constructor when contention is detected.
     *
     * @api
     */
    public function pid(): ?int
    {
        if ($this->handle !== null) {
            return $this->heldPid;
        }

        if (!\is_file($this->lockPath)) {
            return null;
        }

        $raw = @\file_get_contents($this->lockPath);
        if ($raw === false) {
            return null;
        }

        $trimmed = \trim($raw);
        if ($trimmed === '' || !\ctype_digit($trimmed)) {
            return null;
        }

        return (int) $trimmed;
    }

    /**
     * Acquire the lock non-blockingly.
     *
     * On success: opens the lock file (creating it if needed), takes the
     * exclusive lock, truncates the body, writes `getmypid()`, installs
     * signal + shutdown handlers, and stores the handle on `$this`.
     *
     * On failure: raises {@see MigrationConcurrencyException} carrying the
     * lock path and the PID parsed from the existing body (or `null` when
     * the body cannot be parsed).
     *
     * Idempotent: calling `acquire()` while already holding the lock
     * returns silently. Use a separate instance to acquire a different
     * migration id.
     *
     * @throws MigrationConcurrencyException When another process holds the lock.
     * @throws \RuntimeException When the lock directory / file cannot be opened.
     *
     * @spec FR-061
     */
    public function acquire(): void
    {
        if ($this->handle !== null) {
            return;
        }

        $this->ensureLockDir();

        $handle = @\fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException(\sprintf(
                'MigrationLock::acquire(): unable to open lock file %s.',
                $this->lockPath,
            ));
        }

        if (!\flock($handle, \LOCK_EX | \LOCK_NB)) {
            // Another process holds the lock. Read its PID for the
            // exception payload (best-effort — the body may not be
            // populated yet if the holder is still in the window between
            // `flock()` and the PID write).
            $existingPid = $this->pid();
            \fclose($handle);

            $this->logger?->info('MigrationLock: contention detected', [
                'migration_id' => $this->migrationId,
                'lock_path' => $this->lockPath,
                'holding_pid' => $existingPid,
            ]);

            throw new MigrationConcurrencyException(
                migrationId: $this->migrationId,
                lockPath: $this->lockPath,
                holdingPid: $existingPid,
            );
        }

        // Write our PID for operator visibility. `ftruncate()` before
        // writing handles the case where a prior holder crashed with a
        // populated body that we just inherited (the OS released the
        // flock on process death, but the file body persists).
        \ftruncate($handle, 0);
        \rewind($handle);
        $pid = \getmypid();
        \fwrite($handle, $pid . "\n");
        \fflush($handle);

        $this->handle = $handle;
        $this->heldPid = $pid;
        $this->installHandlers();

        $this->logger?->debug('MigrationLock: acquired', [
            'migration_id' => $this->migrationId,
            'lock_path' => $this->lockPath,
            'pid' => \getmypid(),
        ]);
    }

    /**
     * Release the lock if held; safe to call multiple times.
     *
     * Releases the OS-level lock via `flock($handle, LOCK_UN)` and closes
     * the handle. The lock file itself is intentionally left on disk:
     *
     *  - Operators inspect it for the holder PID (see {@see pid()}).
     *  - The next `acquire()` truncates and overwrites the body on success.
     *  - Removing the file here would introduce a TOCTOU window — another
     *    process could `fopen()` a now-stale path between our `unlink()`
     *    and its own `flock()`, then both holders would believe they own
     *    distinct locks against the same migration id.
     *
     * @api
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        $handle = $this->handle;
        $this->handle = null;
        $this->heldPid = null;

        \flock($handle, \LOCK_UN);
        \fclose($handle);

        $this->logger?->debug('MigrationLock: released', [
            'migration_id' => $this->migrationId,
            'lock_path' => $this->lockPath,
        ]);
    }

    /**
     * Ensure the lock directory exists; create it (with secure 0o755
     * permissions) when missing.
     *
     * @throws \RuntimeException When the directory cannot be created.
     */
    private function ensureLockDir(): void
    {
        if (\is_dir($this->lockDir)) {
            return;
        }

        if (!@\mkdir($this->lockDir, 0o755, true) && !\is_dir($this->lockDir)) {
            throw new \RuntimeException(\sprintf(
                'MigrationLock::ensureLockDir(): unable to create lock directory %s.',
                $this->lockDir,
            ));
        }
    }

    /**
     * Wire signal + shutdown handlers exactly once per instance.
     *
     * - `pcntl_signal(SIGTERM/SIGINT, ...)`: releases the lock when the
     *   operator (or systemd, or kubelet) signals the process. Requires
     *   the pcntl extension; logged at info level on Windows builds.
     * - `register_shutdown_function`: catches the no-pcntl path
     *   (Windows, exotic SAPIs) AND any abnormal exit (fatal error,
     *   uncaught exception) where pcntl signals don't fire.
     *
     * @spec FR-062 — graceful release on SIGTERM/SIGINT
     */
    private function installHandlers(): void
    {
        if ($this->handlersInstalled) {
            return;
        }

        $this->handlersInstalled = true;

        // Shutdown function: always installed. Captures `$this` weakly via
        // a closure so the instance can be GC'd if released earlier.
        \register_shutdown_function(function (): void {
            // Defensive: release() is idempotent.
            $this->release();
        });

        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            $this->logger?->info(
                'MigrationLock: pcntl extension missing; lock will release on normal exit only.',
                ['migration_id' => $this->migrationId],
            );
            return;
        }

        \pcntl_async_signals(true);
        $handler = function (int $signal): void {
            $this->logger?->info('MigrationLock: signal received, releasing lock.', [
                'migration_id' => $this->migrationId,
                'signal' => $signal,
            ]);
            $this->release();
            // Re-raise the default disposition by exiting with conventional
            // signal exit code (128 + signal). The shutdown function will
            // run after this exit but release() above already ran.
            exit(128 + $signal);
        };

        // SIGTERM (15) and SIGINT (2) cover graceful operator interrupts.
        // We deliberately do NOT trap SIGKILL (9) — it cannot be trapped.
        \pcntl_signal(\SIGTERM, $handler);
        \pcntl_signal(\SIGINT, $handler);
    }
}
