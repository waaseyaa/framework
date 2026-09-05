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
 * Environment variables through which git selects a repository, work tree,
 * index or object store *instead of* discovering them from the working
 * directory. This is git's own `local_repo_env` list (environment.c) — the
 * set git clears before running a command in another repository.
 *
 * Git hooks export some of these (a pre-push from a linked worktree carries
 * `GIT_DIR=<main>/.git/worktrees/<name>`), so every git child the gates run
 * must drop them: otherwise `git -C $root ...` operates on the hook's
 * repository, not $root — and `git init` on a fixture would "reinitialise"
 * the developer's own gitdir as bare instead of creating the fixture's.
 */
const REPOSITORY_LOCAL_GIT_ENVIRONMENT = [
    'GIT_ALTERNATE_OBJECT_DIRECTORIES',
    'GIT_COMMON_DIR',
    'GIT_CONFIG',
    'GIT_CONFIG_COUNT',
    'GIT_CONFIG_PARAMETERS',
    'GIT_DIR',
    'GIT_GRAFT_FILE',
    'GIT_IMPLICIT_WORK_TREE',
    'GIT_INDEX_FILE',
    'GIT_NO_REPLACE_OBJECTS',
    'GIT_OBJECT_DIRECTORY',
    'GIT_PREFIX',
    'GIT_REPLACE_REF_BASE',
    'GIT_SHALLOW_FILE',
    'GIT_WORK_TREE',
];

/**
 * The current process environment with every repository-selecting git
 * variable removed, so a git child resolves its repository from `-C $root`
 * alone.
 *
 * @return array<string, string>
 */
function repositoryGitEnvironment(): array
{
    $environment = getenv();
    foreach (REPOSITORY_LOCAL_GIT_ENVIRONMENT as $name) {
        unset($environment[$name]);
    }

    return $environment;
}

/**
 * Run one git command against $root with the repository-selecting
 * environment scrubbed (see REPOSITORY_LOCAL_GIT_ENVIRONMENT).
 *
 * @param list<string> $arguments Arguments after `git -C $root`.
 * @return array{0: int, 1: string, 2: string} Exit code, stdout, trimmed stderr.
 *
 * @throws RuntimeException when the git process cannot be started at all.
 */
function repositoryGit(string $root, array $arguments): array
{
    $process = @proc_open(
        ['git', '-C', $root, ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        repositoryGitEnvironment(),
    );
    if (!is_resource($process)) {
        throw new RuntimeException(sprintf('repository-files: unable to start git for %s.', $root));
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

/**
 * Enumerate repository files under $root: tracked files (`--cached`) plus
 * untracked files that are not ignored (`--others --exclude-standard`).
 *
 * `--exclude-standard` honours `.gitignore`, `.git/info/exclude` and the
 * developer's global `core.excludesFile`; the last can hide an untracked new
 * file from a local scan (never a tracked one) until it is added.
 *
 * @param list<string> $pathspecs Relative subtrees or files to restrict the
 *   enumeration to; empty means the whole repository. Passed to git after
 *   `--`, which only separates them from options: they are ordinary git
 *   pathspecs, so callers pass literal repository paths (as the gates do),
 *   not globs.
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
    $arguments = ['ls-files', '-z', '--cached', '--others', '--exclude-standard'];
    $pathspecs = array_values(array_filter($pathspecs, static fn(string $pathspec): bool => $pathspec !== ''));
    if ($pathspecs !== []) {
        $arguments[] = '--';
        array_push($arguments, ...$pathspecs);
    }

    [$exitCode, $listing, $error] = repositoryGit($normalizedRoot, $arguments);
    if ($exitCode !== 0) {
        $hint = str_contains($error, 'dubious ownership')
            ? ' (git refuses a checkout owned by another user; see `git config --global --add safe.directory <path>`)'
            : '';
        throw new RuntimeException(sprintf(
            'repository-files: git could not enumerate repository files under %s (exit %d)%s%s',
            $normalizedRoot,
            $exitCode,
            $error === '' ? '.' : ': ' . $error,
            $hint,
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
