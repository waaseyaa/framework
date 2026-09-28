<?php

declare(strict_types=1);

/** Host-aware gate metadata and material-input evidence for check-pr-preflight. */

const PREFLIGHT_EVIDENCE_SCHEMA_VERSION = 2;

function preflight_host_id(): string
{
    return match (PHP_OS_FAMILY) {
        'Windows' => 'windows',
        'Darwin' => 'darwin',
        'Linux' => 'linux',
        default => strtolower(PHP_OS_FAMILY),
    };
}

/** @return array<string, mixed> */
function preflight_effective_gate(array $manifest, array $gate): array
{
    $defaults = is_array($manifest['gate_defaults'] ?? null) ? $manifest['gate_defaults'] : [];
    $effective = array_replace($defaults, $gate);
    $effective['execution'] ??= 'local';
    $effective['owning_hosted_check'] ??= (string) ($gate['enforced_by'] ?? 'unowned');
    $required = is_array($effective['required_capabilities'] ?? null) ? $effective['required_capabilities'] : [];
    $run = (string) ($gate['run'] ?? '');
    if (preg_match('/^bash(?=\s)/', $run) === 1) {
        $required[] = 'bash';
    }
    if (preg_match('/^composer(?=\s)/', $run) === 1) {
        $required[] = 'composer';
    }
    if (preg_match('/^(?:node|npm|npx)(?=\s)/', $run) === 1) {
        $required[] = 'node';
    }
    $effective['required_capabilities'] = array_values(array_unique($required));

    return $effective;
}

/** @return list<string> */
function preflight_gate_metadata_problems(array $gate): array
{
    $problems = [];
    foreach (['supported_hosts', 'required_capabilities', 'relevant_paths', 'evidence_inputs'] as $field) {
        if (!is_array($gate[$field] ?? null) || $gate[$field] === []) {
            $problems[] = $field . ' must be a non-empty array';
        }
    }
    if (!in_array($gate['cost'] ?? null, ['fast', 'medium', 'slow'], true)) {
        $problems[] = 'cost must be fast, medium, or slow';
    }
    if (!in_array($gate['execution'] ?? null, ['local', 'hosted-only'], true)) {
        $problems[] = 'execution must be local or hosted-only';
    }
    if (!is_string($gate['owning_hosted_check'] ?? null) || $gate['owning_hosted_check'] === '') {
        $problems[] = 'owning_hosted_check must be a non-empty string';
    }

    return $problems;
}

/** @return array{available:bool, detail:string} */
function preflight_command_capability(string $command): array
{
    $windows = PHP_OS_FAMILY === 'Windows';
    $probe = $windows ? ['where.exe', $command] : ['sh', '-c', 'command -v "$1"', 'sh', $command];
    $stdout = repository_bounded_output($probe, 5.0);
    if ($stdout === null) {
        return ['available' => false, 'detail' => 'not found'];
    }
    $stdout = trim($stdout);

    return ['available' => $stdout !== '', 'detail' => $stdout !== '' ? strtok($stdout, "\r\n") : 'not found'];
}

/** @return array<string, array{available:bool, detail:string}> */
function preflight_capabilities(string $root, ?string $hostBash): array
{
    $git = repository_git_command($root)[0] ?? null;

    return [
        'php' => ['available' => true, 'detail' => PHP_BINARY . ' ' . PHP_VERSION],
        'git' => ['available' => is_string($git) && $git !== '', 'detail' => is_string($git) ? $git : 'not found'],
        'bash' => ['available' => $hostBash !== null, 'detail' => $hostBash ?? 'Git for Windows Bash is unavailable'],
        'composer' => preflight_command_capability('composer'),
        'node' => preflight_command_capability('node'),
    ];
}

/** @return list<string> */
function preflight_missing_capabilities(array $gate, array $capabilities): array
{
    $missing = [];
    foreach ($gate['required_capabilities'] as $capability) {
        if (($capabilities[$capability]['available'] ?? false) !== true) {
            $missing[] = (string) $capability;
        }
    }

    return $missing;
}

/** @return array{0:int,1:string} */
function preflight_git(string $root, array $arguments): array
{
    $output = repository_bounded_output(
        [...repository_git_command($root), '-C', $root, ...$arguments],
        30.0,
        64 * 1024 * 1024,
    );
    if ($output === null) {
        return [2, ''];
    }

    return [0, trim($output)];
}

/** @return list<string> */
function preflight_changed_paths(string $root, string $base): array
{
    $paths = [];
    foreach ([
        ['diff', '--name-only', '--diff-filter=ACMR', $base . '...HEAD'],
        ['diff', '--name-only', '--diff-filter=ACMR', '--cached'],
        ['diff', '--name-only', '--diff-filter=ACMR'],
        ['ls-files', '--others', '--exclude-standard'],
    ] as $arguments) {
        [$exit, $output] = preflight_git($root, $arguments);
        if ($exit !== 0) {
            continue;
        }
        foreach (preg_split('/\R/', $output, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $path) {
            $paths[str_replace('\\', '/', $path)] = true;
        }
    }

    return array_keys($paths);
}

function preflight_path_matches(string $path, string $selector): bool
{
    $selector = str_replace('\\', '/', $selector);
    if ($selector === '**' || $selector === '**/*') {
        return true;
    }
    if (str_ends_with($selector, '/**')) {
        return str_starts_with($path, substr($selector, 0, -2));
    }

    return fnmatch($selector, $path);
}

function preflight_gate_is_applicable(array $gate, array $changedPaths): bool
{
    if (in_array('**', $gate['relevant_paths'], true) || in_array('**/*', $gate['relevant_paths'], true)) {
        return true;
    }
    foreach ($changedPaths as $path) {
        foreach ($gate['relevant_paths'] as $selector) {
            if (preflight_path_matches($path, (string) $selector)) {
                return true;
            }
        }
    }

    return $changedPaths === [] && in_array('always', $gate['evidence_inputs'], true);
}

function preflight_default_evidence_dir(string $root): ?string
{
    [$exit, $common] = preflight_git($root, ['rev-parse', '--git-common-dir']);
    if ($exit !== 0 || $common === '') {
        return null;
    }
    if (!preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $common)) {
        $common = $root . '/' . $common;
    }
    $common = str_replace('\\', '/', $common);

    return rtrim($common, '/') . '/qualification/preflight-v2';
}

/** @return array<string, mixed>|null */
function preflight_source_identity(string $root): ?array
{
    [$headExit, $head] = preflight_git($root, ['rev-parse', 'HEAD']);
    [$treeExit, $tree] = preflight_git($root, ['rev-parse', 'HEAD^{tree}']);
    [$statusExit, $status] = preflight_git($root, ['status', '--porcelain=v1', '--untracked-files=all']);
    [$diffExit, $diff] = preflight_git($root, ['diff', '--binary', 'HEAD']);
    [$untrackedExit, $untracked] = preflight_git($root, ['ls-files', '--others', '--exclude-standard']);
    if ($headExit !== 0 || $treeExit !== 0 || $statusExit !== 0 || $diffExit !== 0 || $untrackedExit !== 0) {
        return null;
    }

    $worktree = hash_init('sha256');
    hash_update($worktree, $diff);
    foreach (preg_split('/\R/', $untracked, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $relative) {
        $path = $root . '/' . str_replace('\\', '/', $relative);
        hash_update($worktree, "\0" . $relative . "\0");
        if (is_link($path)) {
            hash_update($worktree, 'link:' . (string) readlink($path));
        } elseif (is_file($path)) {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                return null;
            }
            hash_update_stream($worktree, $handle);
            fclose($handle);
        }
    }

    return ['head' => $head, 'tree' => $tree, 'worktree_sha256' => hash_final($worktree), 'clean' => $status === ''];
}

/**
 * Hash every non-ignored repository path selected by the gate. The path set,
 * entry type, and bytes are all bound, so additions, removals, renames, links,
 * and content changes invalidate the material identity without relying on a
 * filename-only inference.
 */
function preflight_material_paths_digest(string $root, array $selectors): ?string
{
    /** @var array<string, list<array{path:string, entry_sha256:string}>|null> $snapshots */
    static $snapshots = [];
    if (!array_key_exists($root, $snapshots)) {
        $output = repository_bounded_output(
            [...repository_git_command($root), '-C', $root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'],
            30.0,
            64 * 1024 * 1024,
        );
        if ($output === null) {
            $snapshots[$root] = null;
        } else {
            $paths = array_values(array_filter(explode("\0", $output), static fn(string $path): bool => $path !== ''));
            sort($paths, SORT_STRING);
            $snapshot = [];
            foreach ($paths as $relative) {
                $relative = str_replace('\\', '/', $relative);
                $path = $root . '/' . $relative;
                $entry = hash_init('sha256');
                if (is_link($path)) {
                    hash_update($entry, 'link' . "\0" . (string) readlink($path));
                } elseif (!is_file($path)) {
                    hash_update($entry, 'missing');
                } else {
                    hash_update($entry, "file\0");
                    $handle = @fopen($path, 'rb');
                    if ($handle === false) {
                        $snapshots[$root] = null;

                        break;
                    }
                    hash_update_stream($entry, $handle);
                    fclose($handle);
                }
                $snapshot[] = ['path' => $relative, 'entry_sha256' => hash_final($entry)];
            }
            if (!array_key_exists($root, $snapshots)) {
                $snapshots[$root] = $snapshot;
            }
        }
    }
    if ($snapshots[$root] === null) {
        return null;
    }

    $digest = hash_init('sha256');
    foreach ($snapshots[$root] as $entry) {
        foreach ($selectors as $selector) {
            if (!preflight_path_matches($entry['path'], (string) $selector)) {
                continue;
            }
            hash_update($digest, "path\0{$entry['path']}\0{$entry['entry_sha256']}\0");
            break;
        }
    }

    return hash_final($digest);
}

/** @return array{key:string, identity:array<string,mixed>,candidate:array<string,mixed>}|null */
function preflight_evidence_identity(
    string $root,
    array $gate,
    array $source,
    string $resolvedBase,
    array $capabilities,
): ?array {
    $materialPaths = preflight_material_paths_digest($root, $gate['relevant_paths']);
    if ($materialPaths === null) {
        return null;
    }
    $baseSha = null;
    if (($gate['base'] ?? false) === true) {
        [$baseExit, $baseSha] = preflight_git($root, ['rev-parse', $resolvedBase . '^{commit}']);
        if ($baseExit !== 0) {
            return null;
        }
    }
    $identity = [
        'material_paths_sha256' => $materialPaths,
        'base' => $baseSha,
        'composer_lock_sha256' => is_file($root . '/composer.lock') ? hash_file('sha256', $root . '/composer.lock') : null,
        'toolchain' => [
            'os' => preflight_host_id(),
            'php' => PHP_VERSION,
            'capabilities' => $capabilities,
        ],
        'test_plan' => ['gate_profile' => $gate['profile'], 'command' => $gate['run']],
        'gate' => [
            'id' => $gate['id'],
            'definition_sha256' => hash('sha256', json_encode($gate, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'selector' => $gate['relevant_paths'],
            'evidence_inputs' => $gate['evidence_inputs'],
        ],
    ];

    return [
        'key' => hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'identity' => $identity,
        'candidate' => $source,
    ];
}

/** @return array<string, mixed>|null */
function preflight_read_passed_evidence(string $directory, string $key): ?array
{
    $path = rtrim($directory, '/\\') . '/' . $key . '.json';
    if (!is_file($path)) {
        return null;
    }
    try {
        $receipt = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($receipt)
        && ($receipt['schema_version'] ?? null) === PREFLIGHT_EVIDENCE_SCHEMA_VERSION
        && ($receipt['status'] ?? null) === 'passed'
        && ($receipt['key'] ?? null) === $key
        && is_array($receipt['tested_candidate'] ?? null)
        ? $receipt
        : null;
}

function preflight_write_passed_evidence(string $directory, string $key, array $identity, array $candidate, float $elapsed): bool
{
    if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
        return false;
    }
    $receipt = [
        'schema_version' => PREFLIGHT_EVIDENCE_SCHEMA_VERSION,
        'key' => $key,
        'status' => 'passed',
        'material_identity' => $identity,
        'tested_candidate' => $candidate,
        'elapsed_s' => round($elapsed, 3),
        'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    $path = rtrim($directory, '/\\') . '/' . $key . '.json';
    $temporary = $path . '.tmp-' . getmypid();
    try {
        $json = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable) {
        return false;
    }
    if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
        @unlink($temporary);
        return false;
    }
    if (!@rename($temporary, $path)) {
        // Windows rename does not replace an existing file. The target is a
        // content-addressed cache entry, so replacing only that exact key is
        // safe after the complete temporary file exists.
        if (!is_file($path) || !@unlink($path) || !@rename($temporary, $path)) {
            @unlink($temporary);
            return false;
        }
    }

    return true;
}

function preflight_write_report(string $path, array $report): bool
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
        return false;
    }
    $temporary = $path . '.tmp-' . getmypid();
    try {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable) {
        return false;
    }
    if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
        @unlink($temporary);
        return false;
    }
    if (!@rename($temporary, $path)) {
        if (!is_file($path) || !@unlink($path) || !@rename($temporary, $path)) {
            @unlink($temporary);
            return false;
        }
    }

    return true;
}
