<?php

declare(strict_types=1);

require_once __DIR__ . '/repository-git.php';

/**
 * Host-aware argv prefix for starting a repository Bash entrypoint from PHP
 * (#2679).
 *
 * POSIX hosts start `bash` from PATH, exactly as a `bash <script>` Composer
 * script line did. Native Windows never starts a bare `bash`: cmd.exe resolves
 * it to C:\Windows\System32\bash.exe, the WSL launcher, which runs the script
 * with Linux Git against the /mnt/c view of the checkout, where a linked
 * worktree's `gitdir: C:/...` pointer does not resolve. The supported Windows
 * Bash is the one Git for Windows runs installed hooks with: `bin/bash.exe` in
 * the installation that owns the Windows Git executable
 * (repository_git_command(), which honours WAASEYAA_SYSTEM_GIT). That
 * installation is `git --exec-path` (`<installation>/<mingw64|...>/libexec/git-core`)
 * three levels up.
 *
 * $gitExecPath replaces the `git --exec-path` query for tests. Returns null
 * when no Git for Windows Bash can be located; the caller fails closed and
 * never falls back to PATH.
 *
 * Plain functions, no autoloader: the hook scripts run before `composer install`.
 *
 * @return non-empty-list<string>|null
 */
function repository_bash_command(string $root, string $osFamily = PHP_OS_FAMILY, ?string $gitExecPath = null): ?array
{
    if ($osFamily !== 'Windows') {
        return ['bash'];
    }

    $gitExecPath = rtrim(str_replace('\\', '/', trim($gitExecPath ?? repository_git_exec_path($root))), '/');
    if ($gitExecPath === '') {
        return null;
    }
    $installation = dirname($gitExecPath, 3);
    if ($installation === dirname($installation)) {
        return null;
    }
    $bash = $installation . '/bin/bash.exe';

    return is_file($bash) ? [$bash] : null;
}

/**
 * `git --exec-path` of the host's repository Git entrypoint
 * (repository_git_command()), or '' when it cannot start, fails, answers too
 * much, or misses the deadline.
 */
function repository_git_exec_path(string $root, float $timeoutSeconds = 10.0): string
{
    return repository_bounded_output([...repository_git_command($root), '--exec-path'], $timeoutSeconds) ?? '';
}

/**
 * Stdout of a short, read-only command, or null when it cannot start, exits
 * non-zero, writes more than $maxBytes, or misses the deadline.
 *
 * Stdout goes to a temporary file rather than a pipe, so a child cannot block
 * on a full pipe that nobody drains, and at most $maxBytes + 1 bytes are read
 * back. Stdin and stderr are the null device. The capture file is removed
 * best-effort; a removal failure never changes the result.
 *
 * @param non-empty-list<string> $command
 */
function repository_bounded_output(array $command, float $timeoutSeconds, int $maxBytes = 4096): ?string
{
    $capture = tempnam(sys_get_temp_dir(), 'wsy');
    if ($capture === false) {
        return null;
    }

    try {
        $pipes = [];
        $process = @proc_open($command, [0 => ['null'], 1 => ['file', $capture, 'w'], 2 => ['null']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        [$exitCode] = repository_wait_for_child($process, $timeoutSeconds);
        if ($exitCode !== 0) {
            return null;
        }
        $output = @file_get_contents($capture, false, null, 0, $maxBytes + 1);

        return is_string($output) && strlen($output) <= $maxBytes ? $output : null;
    } finally {
        @unlink($capture);
    }
}

/**
 * Wait for a proc_open() child until $timeoutSeconds, then stop it: terminate,
 * wait up to two seconds, kill, and wait up to two more. Only the direct child
 * is signalled; anything it started itself is not tracked.
 *
 * Returns [exit code, true] when the child exited (128 + signal when a signal
 * ended it), [null, true] when it was stopped at the deadline, and
 * [null, false] when it was still running after the kill. The deadline result
 * stands either way: a failed cleanup never turns into an exit code.
 *
 * @param resource $process
 *
 * @return array{int|null, bool}
 */
function repository_wait_for_child($process, float $timeoutSeconds): array
{
    $deadline = hrtime(true) + (int) ($timeoutSeconds * 1e9);
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            // Only this first non-running status carries the real exit code.
            proc_close($process);

            return [$status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'], true];
        }
        usleep(10_000);
    } while (hrtime(true) < $deadline);

    foreach ([15, 9] as $signal) {
        @proc_terminate($process, $signal);
        $grace = hrtime(true) + 2_000_000_000;
        while (proc_get_status($process)['running'] && hrtime(true) < $grace) {
            usleep(10_000);
        }
        if (!proc_get_status($process)['running']) {
            proc_close($process);

            return [null, true];
        }
    }

    // Still running after the kill: proc_close() would block on it, so the
    // handle is left to PHP's non-blocking resource cleanup.
    return [null, false];
}
