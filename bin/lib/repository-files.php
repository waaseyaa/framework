<?php

declare(strict_types=1);

/**
 * Repository-file enumeration for the preflight gate scanners (#2925).
 *
 * A gate scanner must see exactly the tree the developer sees as repository
 * content: tracked files plus untracked files that git would add — and
 * nothing that lives only in a developer clone (nested git worktrees under
 * `.worktrees/` or `.claude/worktrees/`, a populated `packages/<pkg>/vendor/`,
 * build caches). Walking the filesystem and subtracting a hand-maintained
 * path denylist re-created that boundary by hand and missed a case every
 * time a new kind of untracked tree appeared (#2865, #2866, #2925). Asking
 * git instead makes the `.gitignore` boundary the exclusion by construction,
 * and git never descends into another repository's work tree, so a nested
 * checkout is invisible whether or not it is ignored.
 *
 * Plain functions, no autoloader: the gates must run pre-`composer install`.
 */

/**
 * Enumerate repository files under $root: tracked files (`--cached`) plus
 * untracked files that are not ignored (`--others --exclude-standard`).
 *
 * @param list<string> $pathspecs Relative subtrees or files to restrict the
 *   enumeration to; empty means the whole repository. Passed to git after
 *   `--`, so no pathspec magic is interpreted.
 * @return list<string> Sorted, de-duplicated paths relative to $root, using
 *   forward slashes. Only regular files that exist in the work tree are
 *   returned: a tracked path deleted from the worktree, and the bare `dir/`
 *   entry git prints for a nested repository, are both dropped.
 *
 * @throws RuntimeException when git cannot enumerate (git missing, $root not
 *   inside a work tree, or any non-zero exit) — the caller fails closed
 *   rather than falling back to a filesystem walk.
 */
function repositoryFiles(string $root, array $pathspecs = []): array
{
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
    $command = ['git', '-C', $normalizedRoot, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'];
    $pathspecs = array_values(array_filter($pathspecs, static fn(string $pathspec): bool => $pathspec !== ''));
    if ($pathspecs !== []) {
        $command[] = '--';
        array_push($command, ...$pathspecs);
    }

    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException(sprintf(
            'repository-files: unable to start git to enumerate repository files under %s.',
            $normalizedRoot,
        ));
    }

    $listing = (string) stream_get_contents($pipes[1]);
    $error = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            'repository-files: git could not enumerate repository files under %s (exit %d)%s',
            $normalizedRoot,
            $exitCode,
            $error === '' ? '.' : ': ' . $error,
        ));
    }

    $files = [];
    foreach (explode("\0", $listing) as $relative) {
        if ($relative === '' || !is_file($normalizedRoot . '/' . $relative)) {
            continue;
        }
        $files[$relative] = true;
    }
    $paths = array_keys($files);
    sort($paths, SORT_STRING);

    return $paths;
}
