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
 * `git --exec-path` of the host's repository Git entrypoint, or '' when it
 * cannot start or does not answer.
 */
function repository_git_exec_path(string $root): string
{
    $pipes = [];
    $process = @proc_open([...repository_git_command($root), '--exec-path'], [1 => ['pipe', 'w'], 2 => ['null']], $pipes);
    if (!is_resource($process)) {
        return '';
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return proc_close($process) === 0 ? $output : '';
}
