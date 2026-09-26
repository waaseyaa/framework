<?php

declare(strict_types=1);

/**
 * Lane write isolation for agent-run package audits.
 *
 * Audit lanes, verifiers and writers are read-only everywhere except their
 * assigned output area. A prose instruction is not a control: in the groups
 * calibration a writer agent edited skill files it was told not to touch.
 * Take a snapshot before a run and verify it after; verify fails when anything
 * outside the allowed areas changed.
 *
 *   php lane-integrity.php snapshot --out=<file> [--git=<checkout>]... [--tree=<dir>]... [--allow=<dir>]...
 *   php lane-integrity.php verify --snapshot=<file>
 *
 * --git     a Git checkout or linked worktree. Compared: HEAD, the checked-out
 *           branch, `git status` (tracked changes and untracked files, not
 *           ignored ones), the stash list, the repository's local config and
 *           the worktree list. Git runs with GIT_OPTIONAL_LOCKS=0 so the check
 *           itself writes nothing.
 * --tree    any other directory (installed skill copies, a consumer checkout's
 *           vendor directory): every file's size and modification time.
 * --allow   an output area; changes under it are ignored.
 *
 * Exit status: 0 nothing changed outside the allowed areas; 1 something did
 * (each change is printed); 2 usage or runtime error. Verify never repairs
 * anything: report the change and let the maintainer decide.
 */

const LANE_INTEGRITY_VERSION = 1;

/**
 * Run a fixed read-only command without a shell: stdin closed, stdout and
 * stderr to temporary files, so a large output can't deadlock a pipe.
 *
 * @param list<string> $command
 * @return array{0: int, 1: string}
 */
function laneIntegrityRun(array $command, string $cwd): array
{
    $out = tmpfile();
    $errOut = tmpfile();
    if ($out === false || $errOut === false) {
        throw new RuntimeException('lane-integrity: cannot create temporary output files');
    }
    $env = getenv();
    $env['GIT_OPTIONAL_LOCKS'] = '0';
    $env['GIT_TERMINAL_PROMPT'] = '0';
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $out, 2 => $errOut], $pipes, $cwd, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('lane-integrity: cannot start ' . $command[0]);
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    rewind($out);
    $stdout = (string) stream_get_contents($out);
    fclose($out);
    fclose($errOut);

    return [$exit, $stdout];
}

/** Absolute, forward-slashed, case-folded on Windows, so prefixes compare reliably. */
function laneIntegrityNormalize(string $path): string
{
    $real = realpath($path);
    $normalized = rtrim(str_replace('\\', '/', $real === false ? $path : $real), '/');

    return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
}

/** @param list<string> $allow normalized allowed areas */
function laneIntegrityIsAllowed(string $absolute, array $allow): bool
{
    $candidate = PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $absolute)) : str_replace('\\', '/', $absolute);
    foreach ($allow as $area) {
        if ($candidate === $area || str_starts_with($candidate, $area . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * @param callable(list<string>, string): array{0: int, 1: string} $run
 * @param list<string> $allow
 * @return array<string, string|list<string>>
 */
function laneIntegrityGitState(string $root, array $allow, callable $run): array
{
    $git = static function (array $args) use ($root, $run): string {
        [$exit, $stdout] = $run(['git', ...$args], $root);

        return $exit === 0 ? $stdout : "(exit $exit)";
    };
    $status = [];
    foreach (explode("\0", $git(['status', '--porcelain=v1', '-z', '--untracked-files=all'])) as $entry) {
        if (strlen($entry) < 4 || $entry[2] !== ' ') {
            continue;
        }
        if (!laneIntegrityIsAllowed($root . '/' . substr($entry, 3), $allow)) {
            $status[] = $entry;
        }
    }
    sort($status);

    return [
        'head' => trim($git(['rev-parse', 'HEAD'])),
        'branch' => trim($git(['symbolic-ref', '-q', 'HEAD'])),
        'status' => $status,
        'stash' => $git(['stash', 'list']),
        'config' => $git(['config', '--list', '--local']),
        'worktrees' => $git(['worktree', 'list', '--porcelain']),
    ];
}

/**
 * @param list<string> $allow
 * @return array<string, string> relative path => "size:mtime"
 */
function laneIntegrityTreeState(string $root, array $allow): array
{
    $state = [];
    if (!is_dir($root)) {
        return ['' => 'missing'];
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $entry): bool => $entry->getFilename() !== '.git' && !laneIntegrityIsAllowed($entry->getPathname(), $allow),
        ),
    );
    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        $state[$relative] = $entry->getSize() . ':' . $entry->getMTime();
    }
    ksort($state);

    return $state;
}

/**
 * @param list<string> $gitRoots
 * @param list<string> $treeRoots
 * @param list<string> $allow
 * @param callable(list<string>, string): array{0: int, 1: string} $run
 * @return array<string, mixed>
 */
function laneIntegritySnapshot(array $gitRoots, array $treeRoots, array $allow, callable $run): array
{
    $allow = array_map('laneIntegrityNormalize', $allow);
    $snapshot = ['version' => LANE_INTEGRITY_VERSION, 'allow' => $allow, 'git' => [], 'tree' => []];
    foreach ($gitRoots as $root) {
        $snapshot['git'][laneIntegrityNormalize($root)] = laneIntegrityGitState(laneIntegrityNormalize($root), $allow, $run);
    }
    foreach ($treeRoots as $root) {
        $snapshot['tree'][laneIntegrityNormalize($root)] = laneIntegrityTreeState(laneIntegrityNormalize($root), $allow);
    }

    return $snapshot;
}

/**
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return list<string> one line per change outside the allowed areas
 */
function laneIntegrityDiff(array $before, array $after): array
{
    $changes = [];
    foreach ($before['git'] as $root => $state) {
        $now = $after['git'][$root] ?? null;
        if (!is_array($now)) {
            $changes[] = "$root: checkout no longer readable";
            continue;
        }
        foreach (['head' => 'HEAD moved', 'branch' => 'checked-out branch changed', 'stash' => 'stash list changed', 'config' => 'repository config changed', 'worktrees' => 'worktree list changed (possibly another session)'] as $key => $label) {
            if ($state[$key] !== $now[$key]) {
                $changes[] = "$root: $label";
            }
        }
        foreach (array_diff($now['status'], $state['status']) as $entry) {
            $changes[] = "$root: now " . trim(substr($entry, 0, 2)) . ' ' . substr($entry, 3);
        }
        foreach (array_diff($state['status'], $now['status']) as $entry) {
            $changes[] = "$root: no longer " . trim(substr($entry, 0, 2)) . ' ' . substr($entry, 3);
        }
    }
    foreach ($before['tree'] as $root => $files) {
        $now = $after['tree'][$root] ?? [];
        foreach ($files as $path => $stamp) {
            if (!isset($now[$path])) {
                $changes[] = "$root/$path: removed";
            } elseif ($now[$path] !== $stamp) {
                $changes[] = "$root/$path: modified";
            }
        }
        foreach (array_diff_key($now, $files) as $path => $stamp) {
            $changes[] = "$root/$path: added";
        }
    }

    return $changes;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $command = $argv[1] ?? '';
    $options = ['git' => [], 'tree' => [], 'allow' => [], 'out' => null, 'snapshot' => null];
    foreach (array_slice($argv, 2) as $arg) {
        if (preg_match('#^--(git|tree|allow|out|snapshot)=(.+)$#', $arg, $m) !== 1) {
            fwrite(STDERR, "lane-integrity: unknown argument $arg\n");
            exit(2);
        }
        if (is_array($options[$m[1]])) {
            $options[$m[1]][] = $m[2];
        } else {
            $options[$m[1]] = $m[2];
        }
    }
    try {
        if ($command === 'snapshot' && is_string($options['out']) && ($options['git'] !== [] || $options['tree'] !== [])) {
            $snapshot = laneIntegritySnapshot($options['git'], $options['tree'], $options['allow'], 'laneIntegrityRun');
            $snapshot['roots'] = ['git' => $options['git'], 'tree' => $options['tree'], 'allow' => $options['allow']];
            file_put_contents($options['out'], json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fwrite(STDOUT, 'snapshot: ' . count($snapshot['git']) . ' checkout(s), ' . count($snapshot['tree']) . " tree(s)\n");
            exit(0);
        }
        if ($command === 'verify' && is_string($options['snapshot']) && is_file($options['snapshot'])) {
            $before = json_decode((string) file_get_contents($options['snapshot']), true, 64, JSON_THROW_ON_ERROR);
            $after = laneIntegritySnapshot($before['roots']['git'], $before['roots']['tree'], $before['roots']['allow'], 'laneIntegrityRun');
            $changes = laneIntegrityDiff($before, $after);
            foreach ($changes as $change) {
                fwrite(STDOUT, "CHANGED $change\n");
            }
            fwrite(STDOUT, $changes === [] ? "OK nothing changed outside the allowed areas\n" : count($changes) . " change(s) outside the allowed areas\n");
            exit($changes === [] ? 0 : 1);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'lane-integrity: ' . $e->getMessage() . "\n");
        exit(2);
    }
    fwrite(STDERR, "usage: php lane-integrity.php snapshot --out=<file> [--git=<checkout>]... [--tree=<dir>]... [--allow=<dir>]...\n       php lane-integrity.php verify --snapshot=<file>\n");
    exit(2);
}
