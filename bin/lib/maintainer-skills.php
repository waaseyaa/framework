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
const MAINTAINER_SKILLS_FRONTMATTER_KEYS = ['name', 'description', 'license', 'allowed-tools', 'metadata'];

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
        $skills[substr($relative, 0, $separator)][substr($relative, $separator + 1)] = (string) file_get_contents($absolute);
    }
    ksort($skills);
    foreach ($skills as &$files) {
        ksort($files);
    }

    return $skills;
}

/**
 * Structural checks equivalent to the skill-creator `quick_validate.py`
 * contract, plus local link resolution.
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
    foreach (explode("\n", $match[1]) as $line) {
        if ($line === '' || str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
            continue;
        }
        [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
        $frontmatter[trim($key)] = trim($value);
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
 * Regular files under an installed skill directory, excluding the manifest.
 *
 * @return array<string, string> relative path => file bytes
 */
function maintainerSkillsReadInstalled(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
        if ($relative === MAINTAINER_SKILLS_MANIFEST || !$entry->isFile()) {
            continue;
        }
        $files[$relative] = (string) file_get_contents($entry->getPathname());
    }
    ksort($files);

    return $files;
}

/**
 * @return array<string, mixed>|null
 */
function maintainerSkillsReadManifest(string $directory): ?array
{
    $path = $directory . '/' . MAINTAINER_SKILLS_MANIFEST;
    if (!is_file($path)) {
        return null;
    }
    $manifest = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['schema_version'] ?? null) !== MAINTAINER_SKILLS_MANIFEST_SCHEMA || !is_array($manifest['files'] ?? null)) {
        throw new RuntimeException(sprintf('maintainer-skills: %s is not a schema %d manifest.', $path, MAINTAINER_SKILLS_MANIFEST_SCHEMA));
    }

    return $manifest;
}

/**
 * Classify one target skill directory against the source.
 *
 * States: missing, unmanaged, drifted, current, stale.
 *
 * @param array<string, string> $sourceFiles
 * @return array{state: string, detail: list<string>, manifest: array<string, mixed>|null}
 */
function maintainerSkillsInspect(string $directory, array $sourceFiles): array
{
    if (!is_dir($directory)) {
        return ['state' => 'missing', 'detail' => [], 'manifest' => null];
    }
    $installed = maintainerSkillsHashes(maintainerSkillsReadInstalled($directory));
    $manifest = maintainerSkillsReadManifest($directory);
    if ($manifest === null) {
        return ['state' => 'unmanaged', 'detail' => [], 'manifest' => null];
    }

    /** @var array<string, string> $recorded */
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
    $state = $recorded === maintainerSkillsHashes($sourceFiles) ? 'current' : 'stale';

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
 * @param array<string, string> $sourceFiles
 * @param list<string> $previouslyOwned relative paths recorded by the old manifest
 * @param array{commit: string, clean: bool} $provenance
 */
function maintainerSkillsWrite(string $directory, string $name, array $sourceFiles, array $previouslyOwned, array $provenance): void
{
    foreach (array_diff($previouslyOwned, array_keys($sourceFiles)) as $obsolete) {
        @unlink($directory . '/' . $obsolete);
    }
    foreach ($sourceFiles as $relative => $bytes) {
        $target = $directory . '/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o755, true) && !is_dir(dirname($target))) {
            throw new RuntimeException(sprintf('maintainer-skills: cannot create %s.', dirname($target)));
        }
        $temporary = $target . '.tmp-' . bin2hex(random_bytes(4));
        file_put_contents($temporary, $bytes);
        rename($temporary, $target);
    }
    $manifest = [
        'schema_version' => MAINTAINER_SKILLS_MANIFEST_SCHEMA,
        'skill' => $name,
        'source' => 'waaseyaa/framework:' . MAINTAINER_SKILLS_SOURCE . '/' . $name,
        'source_commit' => $provenance['commit'],
        'source_clean' => $provenance['clean'],
        'files' => maintainerSkillsHashes($sourceFiles),
    ];
    file_put_contents(
        $directory . '/' . MAINTAINER_SKILLS_MANIFEST,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
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
