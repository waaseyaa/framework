<?php

declare(strict_types=1);

/**
 * Maintainer skill source, validation, and managed local installation (#3080).
 *
 * `.agents/skills/<name>/` in this repository is the only authority for the
 * Waaseyaa maintainer skills. Codex discovers that directory when it runs
 * inside the Framework checkout; for every other repository the skills are
 * copied into the user's client skill directories. Those copies are generated
 * artifacts: each carries a provenance manifest recording the source commit
 * and a SHA-256 per file, so a stale, drifted, or hand-edited copy is detected
 * instead of silently becoming a second authority.
 *
 * Installation never overwrites bytes it does not own. A target skill
 * directory without a manifest is refused unless `--adopt` is given and its
 * content already equals the source after CRLF normalisation; a managed
 * directory whose files no longer match their manifest is refused as local
 * drift. Every refusal is decided before any write.
 *
 * Plain functions, no autoloader, so the command runs before `composer install`.
 */

require_once __DIR__ . '/repository-files.php';

const MAINTAINER_SKILLS_SOURCE = '.agents/skills';
const MAINTAINER_SKILLS_MANIFEST = '.waaseyaa-skill.json';
const MAINTAINER_SKILLS_MANIFEST_SCHEMA = 1;
/**
 * Test-only fault injection. A comma-separated list of fault points:
 * `read:<skill>/<path>` fails that source read, `write-after:<n>` fails after
 * n files are written, and `rollback` makes rollback deletions fail. Never set
 * it outside tests.
 */
const MAINTAINER_SKILLS_TEST_FAULT = "WAASEYAA_MAINTAINER_SKILLS_TEST_FAULT";
const MAINTAINER_SKILLS_FRONTMATTER_KEYS = ['name', 'description', 'license', 'allowed-tools'];

/**
 * @return array<string, array<string, string>> skill name => relative path => file bytes
 */
function maintainerSkillsSource(string $root): array
{
    $skills = [];
    foreach (repositoryFiles($root, [MAINTAINER_SKILLS_SOURCE]) as $file) {
        $relative = substr($file, strlen(MAINTAINER_SKILLS_SOURCE) + 1);
        $separator = strpos($relative, '/');
        if ($separator === false) {
            continue;
        }
        $absolute = $root . '/' . $file;
        if (is_link($absolute)) {
            throw new RuntimeException(sprintf('maintainer-skills: %s is a symbolic link; skill sources must be regular files.', $file));
        }
        $bytes = maintainerSkillsFault('read:' . $relative) ? false : @file_get_contents($absolute);
        if ($bytes === false) {
            throw new RuntimeException(sprintf('maintainer-skills: cannot read skill source %s: %s', $file, error_get_last()['message'] ?? 'read failed'));
        }
        $skills[substr($relative, 0, $separator)][substr($relative, $separator + 1)] = $bytes;
    }
    ksort($skills);
    foreach ($skills as &$files) {
        ksort($files);
    }

    return $skills;
}

/**
 * Validate one skill source.
 *
 * This is deliberately NARROWER than skill-creator's `quick_validate.py`,
 * which parses frontmatter as full YAML. Here frontmatter must be a flat
 * mapping of single-line scalar values (optionally wrapped in one pair of
 * matching quotes), with no duplicate keys, comments, nesting or blank lines.
 * Anything else is refused rather than guessed at, so a malformed block can
 * never pass. It also refuses unfinished `TODO` markers in any file, CR line
 * endings, and relative Markdown links that don't resolve inside the skill.
 *
 * @param array<string, string> $files
 * @return list<string> problems; empty when valid
 */
function maintainerSkillProblems(string $name, array $files): array
{
    $problems = [];
    if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name) !== 1 || strlen($name) > 64) {
        $problems[] = 'directory name must be hyphen-case and at most 64 characters';
    }
    if (!isset($files['SKILL.md'])) {
        return [...$problems, 'SKILL.md is missing'];
    }

    $body = $files['SKILL.md'];
    if (preg_match('/\A---\n(.*?)\n---\n/s', $body, $match) !== 1) {
        return [...$problems, 'SKILL.md must start with a --- frontmatter block'];
    }
    $frontmatter = [];
    foreach (explode("\n", $match[1]) as $number => $line) {
        if (preg_match('/^([a-z][a-z-]*): (.*)$/', $line, $pair) !== 1) {
            $problems[] = sprintf('frontmatter line %d is not a flat "key: value" pair', $number + 1);
            continue;
        }
        [, $key, $value] = $pair;
        if (array_key_exists($key, $frontmatter)) {
            $problems[] = sprintf('frontmatter key "%s" appears more than once', $key);
            continue;
        }
        $value = trim($value);
        if (preg_match('/^(["\']).*\1$/s', $value) === 1) {
            $value = substr($value, 1, -1);
        } elseif ($value === '' || str_starts_with($value, '"') || str_starts_with($value, "'") || preg_match('/^[\[{>|&*!%@`#]/', $value) === 1 || str_contains($value, ' #')) {
            $problems[] = sprintf('frontmatter key "%s" must have a plain single-line value', $key);
            continue;
        }
        $frontmatter[$key] = $value;
    }
    foreach (array_diff(array_keys($frontmatter), MAINTAINER_SKILLS_FRONTMATTER_KEYS) as $unexpected) {
        $problems[] = sprintf('unexpected frontmatter key "%s"', $unexpected);
    }
    if (($frontmatter['name'] ?? '') !== $name) {
        $problems[] = sprintf('frontmatter name must equal the directory name "%s"', $name);
    }
    $description = $frontmatter['description'] ?? '';
    if ($description === '') {
        $problems[] = 'frontmatter description is required';
    } elseif (strlen($description) > 1024 || str_contains($description, '<') || str_contains($description, '>')) {
        $problems[] = 'frontmatter description must be at most 1024 characters without angle brackets';
    }

    foreach ($files as $relative => $content) {
        if (str_contains($content, "\r")) {
            $problems[] = sprintf('%s contains CR line endings', $relative);
        }
        if (preg_match('/\bTODO\b/', $content) === 1) {
            $problems[] = sprintf('%s contains an unfinished TODO marker', $relative);
        }
        if (!str_ends_with($relative, '.md')) {
            continue;
        }
        preg_match_all('/\]\(([^)\s#]+)(?:#[^)]*)?\)/', $content, $links);
        foreach ($links[1] as $target) {
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) {
                continue;
            }
            $resolved = maintainerSkillsResolveLink(dirname($relative), $target);
            if ($resolved === null || !isset($files[$resolved])) {
                $problems[] = sprintf('%s links to %s, which is not a file in this skill', $relative, $target);
            }
        }
    }

    return $problems;
}

function maintainerSkillsResolveLink(string $directory, string $target): ?string
{
    $parts = [];
    foreach (explode('/', ($directory === '.' ? '' : $directory . '/') . $target) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts === []) {
                return null;
            }
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return implode('/', $parts);
}

/**
 * @return array{commit: string, clean: bool}
 */
function maintainerSkillsProvenance(string $root): array
{
    [$exitCode, $commit, $error] = repositoryGit($root, ['rev-parse', 'HEAD']);
    if ($exitCode !== 0) {
        throw new RuntimeException('maintainer-skills: cannot resolve the source commit: ' . $error);
    }
    [$exitCode, $changes, $error] = repositoryGit($root, ['status', '--porcelain', '--', MAINTAINER_SKILLS_SOURCE]);
    if ($exitCode !== 0) {
        throw new RuntimeException('maintainer-skills: cannot inspect the source tree: ' . $error);
    }

    return ['commit' => trim($commit), 'clean' => trim($changes) === ''];
}

/**
 * @param array<string, string> $files
 * @return array<string, string> relative path => sha256
 */
function maintainerSkillsHashes(array $files): array
{
    return array_map(static fn(string $bytes): string => hash('sha256', $bytes), $files);
}

/**
 * Files under an installed skill directory, excluding the manifest. A
 * symbolic link is never followed; it is reported with a sentinel value so
 * it always shows up as drift.
 *
 * @return array<string, string> relative path => file bytes
 */
function maintainerSkillsReadInstalled(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
        if ($relative === MAINTAINER_SKILLS_MANIFEST) {
            continue;
        }
        if ($entry->isLink()) {
            $files[$relative] = "\0symbolic link\0";
            continue;
        }
        if (!$entry->isFile()) {
            continue;
        }
        $bytes = file_get_contents($entry->getPathname());
        if ($bytes === false) {
            throw new RuntimeException(sprintf('maintainer-skills: cannot read %s.', $entry->getPathname()));
        }
        $files[$relative] = $bytes;
    }
    ksort($files);

    return $files;
}

function maintainerSkillsCanonicalSource(string $name): string
{
    return 'waaseyaa/framework:' . MAINTAINER_SKILLS_SOURCE . '/' . $name;
}

/**
 * A relative path the installer may write or delete: forward slashes, no
 * empty, `.` or `..` segments, no drive or absolute prefix, and never the
 * manifest itself.
 */
function maintainerSkillsIsSafeRelativePath(string $relative): bool
{
    if ($relative === MAINTAINER_SKILLS_MANIFEST || preg_match('#^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$#', $relative) !== 1) {
        return false;
    }
    foreach (explode('/', $relative) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return false;
        }
    }

    return true;
}

/**
 * Read and validate a skill manifest.
 *
 * @return array{manifest: array{schema_version: int, skill: string, source: string, source_commit: string, source_clean: bool, files: array<string, string>}|null, problems: list<string>}
 *   A null manifest with no problems means the directory has no manifest.
 */
function maintainerSkillsReadManifest(string $directory, string $name): array
{
    $path = $directory . '/' . MAINTAINER_SKILLS_MANIFEST;
    if (is_link($path)) {
        return ['manifest' => null, 'problems' => ['manifest is a symbolic link']];
    }
    if (!is_file($path)) {
        return ['manifest' => null, 'problems' => []];
    }
    try {
        $manifest = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['manifest' => null, 'problems' => ['manifest is not valid JSON']];
    }
    if (!is_array($manifest)) {
        return ['manifest' => null, 'problems' => ['manifest is not a JSON object']];
    }

    $problems = [];
    if (($manifest['schema_version'] ?? null) !== MAINTAINER_SKILLS_MANIFEST_SCHEMA) {
        $problems[] = sprintf('schema_version is not %d', MAINTAINER_SKILLS_MANIFEST_SCHEMA);
    }
    if (($manifest['skill'] ?? null) !== $name) {
        $problems[] = sprintf('skill is not "%s"', $name);
    }
    if (($manifest['source'] ?? null) !== maintainerSkillsCanonicalSource($name)) {
        $problems[] = sprintf('source is not "%s"', maintainerSkillsCanonicalSource($name));
    }
    $commit = $manifest['source_commit'] ?? null;
    if (!is_string($commit) || preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})$/', $commit) !== 1) {
        $problems[] = 'source_commit is not a full commit id';
    }
    if (!is_bool($manifest['source_clean'] ?? null)) {
        $problems[] = 'source_clean is not a boolean';
    }
    $files = $manifest['files'] ?? null;
    if (!is_array($files) || $files === [] || array_is_list($files)) {
        $problems[] = 'files is not a non-empty object';
    } else {
        foreach ($files as $relative => $digest) {
            if (!maintainerSkillsIsSafeRelativePath((string) $relative)) {
                $problems[] = sprintf('files lists an unsafe path "%s"', $relative);
            }
            if (!is_string($digest) || preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
                $problems[] = sprintf('files["%s"] is not a SHA-256 digest', $relative);
            }
        }
    }
    if ($problems !== []) {
        return ['manifest' => null, 'problems' => $problems];
    }

    /** @var array{schema_version: int, skill: string, source: string, source_commit: string, source_clean: bool, files: array<string, string>} $manifest */
    return ['manifest' => $manifest, 'problems' => []];
}

/**
 * Classify one target skill directory against the source.
 *
 * States: missing, unmanaged, invalid-manifest, drifted, stale,
 * provenance-stale, current. `current` needs the installed bytes to match the
 * source AND the manifest to record the same source commit and clean flag.
 * Identical bytes from another commit are `provenance-stale`, which install
 * repairs by rewriting only the manifest.
 *
 * @param array<string, string> $sourceFiles
 * @param array{commit: string, clean: bool}|null $provenance current source identity; null skips the provenance comparison
 * @return array{state: string, detail: list<string>, manifest: array{source_commit: string, source_clean: bool, files: array<string, string>}|null}
 */
function maintainerSkillsInspect(string $directory, string $name, array $sourceFiles, ?array $provenance = null): array
{
    if (!is_dir($directory) || is_link($directory)) {
        $occupied = file_exists($directory) || is_link($directory);

        return ['state' => $occupied ? 'unmanaged' : 'missing', 'detail' => [], 'manifest' => null];
    }
    ['manifest' => $manifest, 'problems' => $problems] = maintainerSkillsReadManifest($directory, $name);
    if ($problems !== []) {
        return ['state' => 'invalid-manifest', 'detail' => $problems, 'manifest' => null];
    }
    if ($manifest === null) {
        return ['state' => 'unmanaged', 'detail' => [], 'manifest' => null];
    }

    $installed = maintainerSkillsHashes(maintainerSkillsReadInstalled($directory));
    $recorded = $manifest['files'];
    $drift = [];
    foreach (array_unique([...array_keys($recorded), ...array_keys($installed)]) as $relative) {
        $expected = $recorded[$relative] ?? null;
        $actual = $installed[$relative] ?? null;
        if ($expected !== $actual) {
            $drift[] = match (true) {
                $expected === null => $relative . ' (unexpected file)',
                $actual === null => $relative . ' (missing)',
                default => $relative . ' (modified)',
            };
        }
    }
    sort($drift);
    if ($drift !== []) {
        return ['state' => 'drifted', 'detail' => $drift, 'manifest' => $manifest];
    }

    ksort($recorded);
    if ($recorded !== maintainerSkillsHashes($sourceFiles)) {
        return ['state' => 'stale', 'detail' => [], 'manifest' => $manifest];
    }
    if ($provenance !== null && ($manifest['source_commit'] !== $provenance['commit'] || $manifest['source_clean'] !== $provenance['clean'])) {
        return ['state' => 'provenance-stale', 'detail' => [sprintf('installed from %s%s, source is %s%s', substr($manifest['source_commit'], 0, 12), $manifest['source_clean'] ? '' : ' (dirty)', substr($provenance['commit'], 0, 12), $provenance['clean'] ? '' : ' (dirty)')], 'manifest' => $manifest];
    }
    $state = 'current';

    return ['state' => $state, 'detail' => [], 'manifest' => $manifest];
}

/**
 * @param array<string, string> $sourceFiles
 * @param array<string, string> $installedFiles
 */
function maintainerSkillsEqualIgnoringCrlf(array $sourceFiles, array $installedFiles): bool
{
    if (array_keys($sourceFiles) !== array_keys($installedFiles)) {
        return false;
    }
    foreach ($sourceFiles as $relative => $bytes) {
        if (str_replace("\r\n", "\n", $installedFiles[$relative]) !== $bytes) {
            return false;
        }
    }

    return true;
}

/**
 * Write bytes to $target through a sibling temporary file and a rename, so a
 * reader never sees a partial file. Throws on any failure.
 */
function maintainerSkillsWriteFileAtomically(string $target, string $bytes): void
{
    $temporary = $target . '.tmp-' . bin2hex(random_bytes(4));
    $written = @file_put_contents($temporary, $bytes);
    if ($written !== strlen($bytes)) {
        $reason = error_get_last()['message'] ?? 'short write';
        @unlink($temporary);
        throw new RuntimeException(sprintf('maintainer-skills: cannot write %s: %s', $target, $reason));
    }
    if (!@rename($temporary, $target)) {
        $reason = error_get_last()['message'] ?? 'rename failed';
        @unlink($temporary);
        throw new RuntimeException(sprintf('maintainer-skills: cannot replace %s: %s', $target, $reason));
    }
}

/**
 * Write the manifest atomically. It is always the last write, so it only
 * ever describes a completed installation.
 *
 * @param array<string, string> $sourceFiles
 * @param array{commit: string, clean: bool} $provenance
 */
function maintainerSkillsWriteManifest(string $directory, string $name, array $sourceFiles, array $provenance): void
{
    $manifest = [
        'schema_version' => MAINTAINER_SKILLS_MANIFEST_SCHEMA,
        'skill' => $name,
        'source' => maintainerSkillsCanonicalSource($name),
        'source_commit' => $provenance['commit'],
        'source_clean' => $provenance['clean'],
        'files' => maintainerSkillsHashes($sourceFiles),
    ];
    maintainerSkillsWriteFileAtomically(
        $directory . '/' . MAINTAINER_SKILLS_MANIFEST,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
}

/**
 * Install, update or adopt one skill directory. Obsolete owned files are
 * deleted, every file is written atomically, and the manifest is written last.
 *
 * On failure nothing is assumed. A first installation tries to remove exactly
 * the files and directories it created and checks each removal; the thrown
 * message states whether rollback completed and lists anything left behind.
 * An update or adoption cannot restore overwritten bytes, so it is not rolled
 * back; the caller re-inspects the directory and reports its actual state.
 *
 * @param array<string, string> $sourceFiles
 * @param list<string> $previouslyOwned relative paths from the old, validated manifest
 * @param array{commit: string, clean: bool} $provenance
 */
function maintainerSkillsWrite(string $directory, string $name, array $sourceFiles, array $previouslyOwned, array $provenance, bool $firstInstall): void
{
    $createdDirectories = [];
    $createdFiles = [];
    $written = 0;
    $faultAfter = maintainerSkillsFaultAfterWrites();
    try {
        foreach (array_diff($previouslyOwned, array_keys($sourceFiles)) as $obsolete) {
            $path = $directory . '/' . $obsolete;
            if ((file_exists($path) || is_link($path)) && !@unlink($path)) {
                throw new RuntimeException(sprintf('maintainer-skills: cannot delete %s: %s', $path, error_get_last()['message'] ?? 'unlink failed'));
            }
        }
        foreach ($sourceFiles as $relative => $bytes) {
            $target = $directory . '/' . $relative;
            $missing = [];
            for ($parent = dirname($target); !is_dir($parent) && $parent !== dirname($parent); $parent = dirname($parent)) {
                array_unshift($missing, $parent);
            }
            foreach ($missing as $parent) {
                if (!@mkdir($parent, 0o755)) {
                    throw new RuntimeException(sprintf('maintainer-skills: cannot create %s: %s', $parent, error_get_last()['message'] ?? 'mkdir failed'));
                }
                $createdDirectories[] = $parent;
            }
            $existed = file_exists($target) || is_link($target);
            maintainerSkillsWriteFileAtomically($target, $bytes);
            if (!$existed) {
                $createdFiles[] = $target;
            }
            ++$written;
            if ($faultAfter !== null && $written >= $faultAfter) {
                // A LogicException, so tests prove recovery handles any Throwable.
                throw new LogicException(sprintf('maintainer-skills: injected fault after %d written files.', $written));
            }
        }
        maintainerSkillsWriteManifest($directory, $name, $sourceFiles, $provenance);
    } catch (Throwable $failure) {
        $message = $failure->getMessage();
        if (!$firstInstall) {
            throw new RuntimeException($message . ' No rollback for an existing directory.', 0, $failure);
        }
        $leftBehind = [];
        foreach (array_reverse($createdFiles) as $file) {
            if (maintainerSkillsFault('rollback') || !@unlink($file)) {
                $leftBehind[] = $file;
            }
        }
        foreach (array_reverse($createdDirectories) as $created) {
            if (maintainerSkillsFault('rollback') || !@rmdir($created)) {
                $leftBehind[] = $created;
            }
        }
        $leftBehind = array_values(array_filter($leftBehind, static fn(string $path): bool => file_exists($path) || is_link($path)));
        throw new RuntimeException(
            $leftBehind === []
                ? sprintf('%s Rolled back: removed %d files and %d directories.', $message, count($createdFiles), count($createdDirectories))
                : sprintf('%s ROLLBACK INCOMPLETE, left behind: %s', $message, implode(', ', $leftBehind)),
            0,
            $failure,
        );
    }
}

/**
 * Default client skill directories: Codex honours CODEX_HOME, Claude Code
 * honours CLAUDE_CONFIG_DIR; both default under the user's home directory.
 *
 * @return list<string>
 */
function maintainerSkillsDefaultTargets(): array
{
    $home = maintainerSkillsEnvironment('HOME') ?? maintainerSkillsEnvironment('USERPROFILE');
    if ($home === null) {
        throw new RuntimeException('maintainer-skills: HOME or USERPROFILE must be set, or pass --target.');
    }
    $codex = maintainerSkillsEnvironment('CODEX_HOME') ?? $home . '/.codex';
    $claude = maintainerSkillsEnvironment('CLAUDE_CONFIG_DIR') ?? $home . '/.claude';

    return [
        str_replace('\\', '/', $codex) . '/skills',
        str_replace('\\', '/', $claude) . '/skills',
    ];
}

function maintainerSkillsEnvironment(string $name): ?string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : null;
}

function maintainerSkillsFault(string $point): bool
{
    $faults = maintainerSkillsEnvironment(MAINTAINER_SKILLS_TEST_FAULT);

    return $faults !== null && in_array($point, explode(',', $faults), true);
}

/**
 * The configured `write-after:<n>` fault threshold, if any.
 */
function maintainerSkillsFaultAfterWrites(): ?int
{
    foreach (explode(',', maintainerSkillsEnvironment(MAINTAINER_SKILLS_TEST_FAULT) ?? '') as $point) {
        if (str_starts_with($point, 'write-after:')) {
            return (int) substr($point, 12);
        }
    }

    return null;
}
