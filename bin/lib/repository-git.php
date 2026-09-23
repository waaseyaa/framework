<?php

declare(strict_types=1);

/**
 * Host-aware argv prefix for starting the repository Git entrypoint from a
 * PHP gate (#3096).
 *
 * docs/governance/agent-contract.md ("Starting and isolating work"): on
 * supported POSIX hosts use the repository `bin/git` adapter, which refuses
 * `git stash`; on native Windows, where `bin/git` is a POSIX-only Bash
 * entrypoint, use the Windows Git executable. Windows CreateProcess cannot
 * start the extensionless Bash script at all (ERROR_BAD_EXE_FORMAT, "%1 is not
 * a valid Win32 application"), so a gate that spawned it there reported an
 * unstartable child as a repository defect.
 *
 * On Windows a harness may pin the executable with WAASEYAA_SYSTEM_GIT, the
 * variable `bin/git` itself honours on POSIX; otherwise bare `git` lets
 * CreateProcess resolve git.exe from PATH. An executable that cannot start
 * fails the caller closed; it never reads as a pass.
 *
 * Only for proc_open() argument arrays issuing fixed read-only subcommands:
 * on Windows the stash refusal is the harness's responsibility, not the
 * adapter's.
 *
 * Plain functions, no autoloader: the gates must run pre-`composer install`.
 *
 * @return non-empty-list<string>
 */
function repository_git_command(string $root, string $osFamily = PHP_OS_FAMILY): array
{
    if ($osFamily !== 'Windows') {
        return [$root . '/bin/git'];
    }

    $pinned = getenv('WAASEYAA_SYSTEM_GIT');

    return [is_string($pinned) && $pinned !== '' ? $pinned : 'git'];
}
