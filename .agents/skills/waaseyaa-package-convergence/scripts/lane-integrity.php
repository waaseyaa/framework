<?php

declare(strict_types=1);

/**
 * Lane write isolation for agent-run package audits.
 *
 * Audit lanes, verifiers and writers are read-only everywhere except their
 * assigned output area. A prose instruction is not a control: in the groups
 * calibration a writer agent edited skill files it was told not to touch.
 * Take a snapshot before a run and verify it after; verify fails when anything
 * outside the allowed areas changed, and fails closed when it can't tell.
 *
 *   php lane-integrity.php snapshot --out=<file> [--git=<checkout>]... [--tree=<dir>]... [--allow=<dir>]...
 *   php lane-integrity.php verify --snapshot=<file> --sha256=<digest printed by snapshot>
 *
 * --git     a Git checkout or linked worktree. Compared: HEAD, the checked-out
 *           branch, every `git status` entry (tracked changes, rename sources
 *           and untracked files, not ignored ones) with a content hash of each
 *           path, the full working-tree and index diffs (hashed), every ref,
 *           the stash list, the repository's local config and the worktree list. Git
 *           runs with GIT_OPTIONAL_LOCKS=0 so the check itself writes nothing.
 * --tree    any other directory (installed skill copies, a consumer checkout's
 *           vendor directory): every file's content hash (symlinks by target).
 * --allow   an output area; changes under it are ignored.
 *
 * The snapshot file must live outside every allowed area (an agent that can
 * write there could rewrite it), and verify checks its digest.
 *
 * Exit status: 0 nothing changed outside the allowed areas; 1 something did
 * (each change is printed); 2 the check could not run or could not be trusted
 * (a Git command failed, a root vanished, the snapshot was altered, bad
 * usage). Treat 2 like 1: stop. Verify never repairs anything.
 */

const LANE_INTEGRITY_VERSION = 2;

/**
 * Run a fixed read-only command without a shell: stdin closed at once, stdout
 * and stderr to temporary files, so a large output can't deadlock a pipe.
 *
 * @param list<string> $command
 * @return array{0: int, 1: string}
 */
function laneIntegrityRun(array $command, string $cwd): array
{
    $out = tmpfile();
    $errOut = tmpfile();
    if ($out === false || $errOut === false) {
        throw new RuntimeException('cannot create temporary output files');
    }
    // Drop anything that points Git at a different repository, index or config
    // than the checkout being inspected (hooks export some of these).
    $env = array_filter(
        getenv(),
        static fn(string $name): bool => preg_match('#^GIT_(DIR|WORK_TREE|INDEX_FILE|COMMON_DIR|OBJECT_DIRECTORY|ALTERNATE_OBJECT_DIRECTORIES|NAMESPACE|PREFIX|IMPLICIT_WORK_TREE|CONFIG.*|GRAFT_FILE|REPLACE_REF_BASE|NO_REPLACE_OBJECTS|SHALLOW_FILE)$#i', $name) !== 1,
        ARRAY_FILTER_USE_KEY,
    );
    $env['GIT_OPTIONAL_LOCKS'] = '0';
    $env['GIT_TERMINAL_PROMPT'] = '0';
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $out, 2 => $errOut], $pipes, $cwd, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start ' . $command[0]);
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
    $candidate = str_replace('\\', '/', $absolute);
    if (PHP_OS_FAMILY === 'Windows') {
        $candidate = strtolower($candidate);
    }
    foreach ($allow as $area) {
        if ($candidate === $area || str_starts_with($candidate, $area . '/')) {
            return true;
        }
    }

    return false;
}

/** A path's content identity; never silently empty. */
function laneIntegrityHash(string $path): string
{
    if (is_link($path)) {
        return 'link:' . (string) readlink($path);
    }
    if (is_dir($path)) {
        return 'dir';
    }
    if (!file_exists($path)) {
        return 'absent';
    }
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException("cannot read $path");
    }

    return $hash;
}

/**
 * @param callable(list<string>, string): array{0: int, 1: string} $run
 * @param list<int> $okExits
 */
function laneIntegrityGit(string $root, array $args, callable $run, array $okExits = [0]): string
{
    [$exit, $stdout] = $run(['git', ...$args], $root);
    if (!in_array($exit, $okExits, true)) {
        throw new RuntimeException(sprintf('git %s failed in %s (exit %d)', implode(' ', $args), $root, $exit));
    }

    return $stdout;
}

/**
 * @param callable(list<string>, string): array{0: int, 1: string} $run
 * @param list<string> $allow
 * @return array<string, mixed>
 */
function laneIntegrityGitState(string $root, array $allow, callable $run): array
{
    // Git searches upward, so a directory inside another checkout would
    // silently snapshot that checkout instead.
    $top = laneIntegrityNormalize(trim(laneIntegrityGit($root, ['rev-parse', '--show-toplevel'], $run)));
    if ($top !== $root) {
        throw new RuntimeException("$root is not the top of a Git checkout (git reports $top)");
    }

    // Pathspecs that keep allowed areas inside this checkout out of the diffs.
    // Allowed areas are case-folded on Windows, so match them that way.
    $excludes = [];
    foreach ($allow as $area) {
        if (str_starts_with($area, $root . '/')) {
            $excludes[] = ':(exclude' . (PHP_OS_FAMILY === 'Windows' ? ',icase' : '') . ')' . substr($area, strlen($root) + 1);
        }
    }

    $status = [];
    $content = [];
    $fields = explode("\0", laneIntegrityGit($root, ['status', '--porcelain=v1', '-z', '--untracked-files=all'], $run));
    for ($i = 0; $i < count($fields); $i++) {
        $entry = $fields[$i];
        if ($entry === '') {
            continue;
        }
        if (strlen($entry) < 4 || $entry[2] !== ' ') {
            throw new RuntimeException("unparseable git status entry in $root: $entry");
        }
        $code = substr($entry, 0, 2);
        $paths = [substr($entry, 3)];
        if (str_contains('RC', $code[0]) || str_contains('RC', $code[1])) {
            $origin = $fields[++$i] ?? '';
            if ($origin === '') {
                throw new RuntimeException("git status rename without a source in $root: $entry");
            }
            $paths[] = $origin;
        }
        foreach ($paths as $n => $relative) {
            if (laneIntegrityIsAllowed($root . '/' . $relative, $allow)) {
                continue;
            }
            $status[] = $code . ' ' . ($n === 0 ? '' : 'from ') . $relative;
            $content[$relative] = laneIntegrityHash($root . '/' . $relative);
        }
    }
    sort($status);
    ksort($content);

    return [
        'head' => trim(laneIntegrityGit($root, ['rev-parse', '--verify', '-q', 'HEAD'], $run, [0, 1])),
        'branch' => trim(laneIntegrityGit($root, ['branch', '--show-current'], $run)),
        'status' => $status,
        'content' => $content,
        'worktree_diff' => hash('sha256', laneIntegrityGit($root, ['diff', '--no-ext-diff', '--no-textconv', '--binary', '--', '.', ...$excludes], $run)),
        'index_diff' => hash('sha256', laneIntegrityGit($root, ['diff', '--cached', '--no-ext-diff', '--no-textconv', '--binary', '--', '.', ...$excludes], $run)),
        'refs' => hash('sha256', laneIntegrityGit($root, ['for-each-ref', '--format=%(refname) %(objectname)'], $run)),
        'stash' => laneIntegrityGit($root, ['stash', 'list'], $run),
        'config' => laneIntegrityGit($root, ['config', '--list', '--local'], $run),
        'worktrees' => laneIntegrityGit($root, ['worktree', 'list', '--porcelain'], $run),
    ];
}

/**
 * @param list<string> $allow
 * @return array<string, string> relative path => content hash
 */
function laneIntegrityTreeState(string $root, array $allow): array
{
    if (!is_dir($root)) {
        throw new RuntimeException("tree root $root is not a directory");
    }
    $state = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $entry): bool => $entry->getFilename() !== '.git' && !laneIntegrityIsAllowed($entry->getPathname(), $allow),
        ),
    );
    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        $state[$relative] = laneIntegrityHash($entry->getPathname());
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
        $normalized = laneIntegrityNormalize($root);
        if (!is_dir($normalized)) {
            throw new RuntimeException("git root $root is not a directory");
        }
        $snapshot['git'][$normalized] = laneIntegrityGitState($normalized, $allow, $run);
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
            $changes[] = "$root: checkout missing from the second snapshot";
            continue;
        }
        foreach ([
            'head' => 'HEAD moved',
            'branch' => 'checked-out branch changed',
            'worktree_diff' => 'tracked working-tree content changed',
            'index_diff' => 'staged content changed',
            'refs' => 'a branch, tag or remote-tracking ref changed (possibly another session)',
            'stash' => 'stash list changed',
            'config' => 'repository config changed',
            'worktrees' => 'worktree list changed (possibly another session)',
        ] as $key => $label) {
            if ($state[$key] !== $now[$key]) {
                $changes[] = "$root: $label";
            }
        }
        foreach (array_diff($now['status'], $state['status']) as $entry) {
            $changes[] = "$root: now $entry";
        }
        foreach (array_diff($state['status'], $now['status']) as $entry) {
            $changes[] = "$root: no longer $entry";
        }
        foreach ($state['content'] as $path => $hash) {
            if (isset($now['content'][$path]) && $now['content'][$path] !== $hash) {
                $changes[] = "$root/$path: content changed";
            }
        }
    }
    foreach ($before['tree'] as $root => $files) {
        $now = $after['tree'][$root] ?? [];
        foreach ($files as $path => $hash) {
            if (!isset($now[$path])) {
                $changes[] = "$root/$path: removed";
            } elseif ($now[$path] !== $hash) {
                $changes[] = "$root/$path: modified";
            }
        }
        foreach (array_diff_key($now, $files) as $path => $hash) {
            $changes[] = "$root/$path: added";
        }
    }

    return $changes;
}

/**
 * The command-line entry point, callable from tests.
 *
 * @param list<string> $argv
 * @param callable(list<string>, string): array{0: int, 1: string} $run
 * @param resource $stdout
 * @param resource $stderr
 */
function laneIntegrityMain(array $argv, callable $run, $stdout, $stderr): int
{
    $command = $argv[1] ?? '';
    $options = ['git' => [], 'tree' => [], 'allow' => [], 'out' => null, 'snapshot' => null, 'sha256' => null];
    foreach (array_slice($argv, 2) as $arg) {
        if (preg_match('#^--(git|tree|allow|out|snapshot|sha256)=(.+)$#', $arg, $m) !== 1) {
            fwrite($stderr, "lane-integrity: unknown argument $arg\n");

            return 2;
        }
        if (is_array($options[$m[1]])) {
            $options[$m[1]][] = $m[2];
        } else {
            $options[$m[1]] = $m[2];
        }
    }
    try {
        if ($command === 'snapshot' && is_string($options['out']) && ($options['git'] !== [] || $options['tree'] !== [])) {
            $allow = array_map('laneIntegrityNormalize', $options['allow']);
            if (laneIntegrityIsAllowed(laneIntegrityNormalize(dirname($options['out'])) . '/' . basename($options['out']), $allow)) {
                fwrite($stderr, "lane-integrity: --out must be outside every allowed area\n");

                return 2;
            }
            $snapshot = laneIntegritySnapshot($options['git'], $options['tree'], $options['allow'], $run);
            $snapshot['roots'] = ['git' => $options['git'], 'tree' => $options['tree'], 'allow' => $options['allow']];
            $bytes = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($options['out'], $bytes) !== strlen($bytes)) {
                throw new RuntimeException('cannot write ' . $options['out']);
            }
            fwrite($stdout, sprintf("snapshot: %d checkout(s), %d tree(s)\nsha256: %s\n", count($snapshot['git']), count($snapshot['tree']), hash('sha256', $bytes)));

            return 0;
        }
        if ($command === 'verify' && is_string($options['snapshot']) && is_string($options['sha256'])) {
            $bytes = file_get_contents($options['snapshot']);
            if ($bytes === false || !hash_equals(strtolower($options['sha256']), hash('sha256', $bytes))) {
                fwrite($stderr, "lane-integrity: the snapshot is missing or doesn't match its digest\n");

                return 2;
            }
            $before = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (($before['version'] ?? null) !== LANE_INTEGRITY_VERSION) {
                fwrite($stderr, "lane-integrity: snapshot version mismatch; take a new snapshot\n");

                return 2;
            }
            $after = laneIntegritySnapshot($before['roots']['git'], $before['roots']['tree'], $before['roots']['allow'], $run);
            $changes = laneIntegrityDiff($before, $after);
            foreach ($changes as $change) {
                fwrite($stdout, "CHANGED $change\n");
            }
            fwrite($stdout, $changes === [] ? "OK nothing changed outside the allowed areas\n" : count($changes) . " change(s) outside the allowed areas\n");

            return $changes === [] ? 0 : 1;
        }
    } catch (Throwable $e) {
        fwrite($stderr, 'lane-integrity: ' . $e->getMessage() . "\n");

        return 2;
    }
    fwrite($stderr, "usage: php lane-integrity.php snapshot --out=<file> [--git=<checkout>]... [--tree=<dir>]... [--allow=<dir>]...\n       php lane-integrity.php verify --snapshot=<file> --sha256=<digest>\n");

    return 2;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(laneIntegrityMain($argv, 'laneIntegrityRun', STDOUT, STDERR));
}
