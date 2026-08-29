<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

/**
 * The host filesystem semantics site initialization depends on.
 *
 * Injecting this rather than branching on `DIRECTORY_SEPARATOR` inline is what
 * makes the Windows path testable from a Linux runner — the repository has no
 * Windows unit-test host, so an untestable branch would be an unverified claim.
 *
 * @internal
 */
enum SiteHostPlatform
{
    case Posix;
    case Windows;

    public static function host(): self
    {
        return PHP_OS_FAMILY === 'Windows' ? self::Windows : self::Posix;
    }

    /**
     * Whether a directory handle can be opened and flushed.
     *
     * The initializer fsyncs each written file and its parent directory so that
     * a committed transaction survives host death, not merely process death.
     * That requires `fopen()` on a directory, which is POSIX-only; on Windows it
     * fails outright and aborted `site:init` with "Unable to sync directory".
     *
     * Skipping it narrows the guarantee rather than removing it: the journal,
     * the lock, and the write-then-rename ordering are unchanged, so the
     * transaction stays atomic and recoverable across process death. Only
     * host-crash durability is POSIX-only, and that is a property the platform
     * does not offer through this interface anyway.
     */
    public function synchronizesDirectories(): bool
    {
        return $this === self::Posix;
    }

    /**
     * Whether POSIX permission bits are meaningful on this host.
     *
     * Windows does not model 0644/0755, so comparing `fileperms() & 0o777`
     * against a declared mode never matches. Left unguarded, the
     * unchanged-artifact short circuit never fires and every artifact is
     * rewritten on every run, so regeneration is not idempotent.
     * `SiteDoctorService` already guards the same comparison this way.
     */
    public function enforcesPermissionBits(): bool
    {
        return $this === self::Posix;
    }

    /**
     * Whether `lstat()['nlink']` is a meaningful aliasing check.
     *
     * The initializer refuses to write through a generated target that is
     * hard-linked elsewhere, since that would let a second name observe or
     * divert governed bytes. The link count is a POSIX guarantee; on Windows
     * the value is not a portable signal, and treating a non-1 result as an
     * attack would refuse ordinary files.
     *
     * The symlink and regular-file halves of that check are unconditional and
     * still run, so the guard narrows one clause rather than removing the test.
     */
    public function enforcesHardLinkCounts(): bool
    {
        return $this === self::Posix;
    }
}
