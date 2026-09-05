<?php

declare(strict_types=1);

/**
 * Public-surface parity check.
 *
 * Enforces docs/specs/stability-charter.md §8.1 / §2.5 and
 * docs/specs/public-surface-declarations.md §7 (FW-DELIVERY-SURFACE-01 /
 * #2901). Run by .github/workflows/surface-parity.yml and runnable locally:
 *
 *   php tools/check-surface-parity.php --base=origin/main
 *
 * Contract: the single editable authority for an element's disposition is
 * now `packages/<pkg>/public-surface.php` (docs/specs/public-surface-declarations.md
 * §2), not docs/public-surface-map.php — that file, and its .md companion,
 * are DERIVED VIEWS composed by bin/generate-surface-map. This gate:
 *
 *   1. loads and validates HEAD's declarations (§4: missing/duplicate/
 *      orphaned/contradictory/invalid all fail closed, naming the offender —
 *      this single step replaces the old gate's separate "source -> map"
 *      untracked-element scan and "map -> source" stale-entry scan, because
 *      §4's "missing" and "orphaned" checks are exactly those two
 *      directions against the declaration plane instead of the aggregate);
 *   2. composes HEAD's declarations into the FQCN => disposition map;
 *   3. loads the merge base's declarations and composes them the same way
 *      (a merge base with no declaration files at all — one that predates
 *      the plane — contributes its tracked docs/public-surface-map.php
 *      instead; an empty base map is never compared against, exit 2),
 *      then applies the UNCHANGED SurfaceChangeAuthorization removal/rename/
 *      downgrade rules between the two composed maps — current-change
 *      changelog-fragment authorization only, exactly as before (§5);
 *   4. applies the §6 tracked/generated boundary rule to both
 *      docs/public-surface-map.php and .md: each must be byte-identical to
 *      either the merge base's tracked bytes, or a fresh generation from
 *      HEAD's declarations — anything else is a hand edit and fails, naming
 *      the file.
 *
 * Exit codes:
 *   0 — parity verified, no drift
 *   1 — drift detected (validation failure, unauthorized removal/downgrade,
 *       or a hand-edited aggregate)
 *   2 — infrastructure failure (declarations unreadable, parser unavailable,
 *       git failure, unparsable layer table)
 */

use Waaseyaa\Tooling\ChangelogFragments;
use Waaseyaa\Tooling\SurfaceChangeAuthorization;
use Waaseyaa\Tooling\SurfaceDeclarations;
use Waaseyaa\Tooling\SurfaceMapView;
use Waaseyaa\Tooling\SurfaceScanner;

$root = dirname(__DIR__);

$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "surface-parity: vendor/autoload.php not found — run `composer install` first.\n");
    exit(2);
}
require $autoload;

const SURFACE_MAP_PHP_REL = 'docs/public-surface-map.php';
const SURFACE_MAP_MD_REL = 'docs/public-surface-map.md';
const FRAGMENT_DIR_REL = 'changes/unreleased';

require_once __DIR__ . '/lib/SurfaceScanner.php';
require_once __DIR__ . '/lib/SurfaceDeclarations.php';
require_once __DIR__ . '/lib/SurfaceMapView.php';
require_once __DIR__ . '/lib/SurfaceChangeAuthorization.php';
require_once __DIR__ . '/lib/ChangelogFragments.php';

function info(string $msg): void
{
    fwrite(STDOUT, "surface-parity: {$msg}\n");
}

function fail(string $msg, int $exit = 1): never
{
    fwrite(STDERR, "surface-parity: {$msg}\n");
    exit($exit);
}

/** @return array{string, int} */
function surfaceGit(string $root, array $arguments): array
{
    $bash = 'bash';
    if (PHP_OS_FAMILY === 'Windows') {
        $whereOutput = shell_exec('where git 2>NUL');
        $gitExecutables = preg_split('/\R/', (string) $whereOutput);
        foreach ($gitExecutables === false ? [] : $gitExecutables as $gitExecutable) {
            $candidate = dirname(dirname($gitExecutable)) . '/bin/bash.exe';
            if ($gitExecutable !== '' && is_file($candidate)) {
                $bash = $candidate;
                break;
            }
        }
    }
    $command = array_merge([$bash, $root . '/bin/git', '-C', $root], $arguments);
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return ['', 127];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [(string) $stdout . ($exitCode === 0 ? '' : (string) $stderr), $exitCode];
}

/**
 * The merge base's tracked docs/public-surface-map.php as a validated
 * FQCN => disposition map — the authority a pre-migration base carried.
 *
 * @return array<string, string>
 */
function loadBaseAggregate(string $root, string $mergeBase): array
{
    [$source, $exitCode] = surfaceGit($root, ['show', $mergeBase . ':' . SURFACE_MAP_PHP_REL]);
    if ($exitCode !== 0 || $source === '') {
        fail("merge base {$mergeBase} has neither package-local declarations nor a readable " . SURFACE_MAP_PHP_REL . ': ' . trim($source), 2);
    }

    $temporary = tempnam(sys_get_temp_dir(), 'waaseyaa-surface-map-');
    if ($temporary === false || file_put_contents($temporary, $source) === false) {
        fail('could not materialize the merge-base surface map for comparison.', 2);
    }
    try {
        /** @var mixed $map */
        $map = require $temporary;
    } finally {
        @unlink($temporary);
    }
    if (!is_array($map) || $map === []) {
        fail('merge-base ' . SURFACE_MAP_PHP_REL . ' must return a non-empty array.', 2);
    }

    $validated = [];
    foreach ($map as $fqcn => $disposition) {
        if (!is_string($fqcn) || !is_string($disposition) || !in_array($disposition, SurfaceDeclarations::ALLOWED_DISPOSITIONS, true)) {
            fail('merge-base ' . SURFACE_MAP_PHP_REL . ' carries an invalid entry: ' . var_export($fqcn, true) . ' => ' . var_export($disposition, true), 2);
        }
        $validated[$fqcn] = $disposition;
    }

    return $validated;
}

/** @return list<string> */
function addedFragmentFilenames(string $root, string $mergeBase): array
{
    $paths = [];
    foreach ([
        ['diff', '--name-only', '--diff-filter=A', $mergeBase . '...HEAD', '--', FRAGMENT_DIR_REL],
        ['diff', '--name-only', '--diff-filter=A', 'HEAD', '--', FRAGMENT_DIR_REL],
        ['ls-files', '--others', '--exclude-standard', '--', FRAGMENT_DIR_REL],
    ] as $arguments) {
        [$output, $exitCode] = surfaceGit($root, $arguments);
        if ($exitCode !== 0) {
            fail('cannot compute current changelog-fragment additions: ' . trim($output), 2);
        }
        foreach (preg_split('/\R/', trim($output)) ?: [] as $path) {
            if ($path !== '' && str_ends_with($path, '.md')) {
                $paths[basename($path)] = true;
            }
        }
    }

    $filenames = array_keys($paths);
    usort($filenames, 'strcmp');

    return $filenames;
}

function surfaceTypeExists(string $fqcn): bool
{
    return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn);
}

$baseRef = 'origin/main';
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--base=')) {
        $baseRef = substr($argument, strlen('--base='));
        continue;
    }
    fail("unknown argument {$argument}; expected --base=<ref>.", 2);
}
if ($baseRef === '') {
    fail('the comparison base cannot be empty.', 2);
}

// ---------------------------------------------------------------------------
// Phase 1: Load + validate HEAD's package-local declarations (§4).
// ---------------------------------------------------------------------------

try {
    $headDeclarations = SurfaceDeclarations::load($root);
    $scanner = SurfaceScanner::scan($root);
} catch (\Throwable $e) {
    fail('infrastructure failure while loading declarations: ' . $e->getMessage(), 2);
}

$validationErrors = $headDeclarations->validate($scanner);
if ($validationErrors !== []) {
    fail("declaration validation failed (docs/specs/public-surface-declarations.md §4):\n\n" . implode("\n\n", $validationErrors));
}

$headMap = $headDeclarations->compose();
$unloadableDeclarations = [];
foreach (array_keys($headMap) as $fqcn) {
    if (!surfaceTypeExists($fqcn)) {
        $unloadableDeclarations[] = $fqcn;
    }
}
if ($unloadableDeclarations !== []) {
    sort($unloadableDeclarations, SORT_STRING);
    fail(
        "declaration validation failed (docs/specs/public-surface-declarations.md §4):\n\n"
        . count($unloadableDeclarations) . " declared type(s) do not load through the repository autoloader:\n  "
        . implode("\n  ", $unloadableDeclarations),
    );
}
info(sprintf(
    'Loaded %d disposition(s) from %d package declaration file(s); scanned %d source file(s).',
    count($headMap),
    count($headDeclarations->packages()),
    $scanner->fileCount(),
));

// ---------------------------------------------------------------------------
// Phase 2: Merge-base declarations + current-change authorization (§5).
// ---------------------------------------------------------------------------

[$mergeBase, $mergeBaseExit] = surfaceGit($root, ['merge-base', 'HEAD', $baseRef]);
$mergeBase = trim($mergeBase);
if ($mergeBaseExit !== 0 || $mergeBase === '') {
    fail("cannot resolve merge base between HEAD and {$baseRef}; fetch the base ref or pass --base=<ref>.", 2);
}

try {
    $baseDeclarations = SurfaceDeclarations::loadAt($root, $mergeBase);
} catch (\Throwable $e) {
    fail("cannot load declarations at merge base {$mergeBase}: " . $e->getMessage(), 2);
}
if ($baseDeclarations->packages() === []) {
    // A merge base that predates the declaration plane (no
    // packages/*/public-surface.php at all) still carried its authority in the
    // tracked docs/public-surface-map.php. Comparing against an EMPTY composed
    // map would make every removal and downgrade invisible, so read the base's
    // aggregate instead — the pre-migration gate's own base — and fail closed
    // (exit 2) when the base has neither. Review-observed regression: removing
    // a governed public entry and regenerating the aggregate passed against
    // 5bac44286 before this fallback existed.
    $baseMap = loadBaseAggregate($root, $mergeBase);
    info('Merge base carries no package-local declarations; comparing against its tracked ' . SURFACE_MAP_PHP_REL . ' instead.');
} else {
    $baseMap = $baseDeclarations->compose();
}
if ($baseMap === []) {
    fail("merge base {$mergeBase} yields an empty disposition map; refusing to authorize against nothing.", 2);
}

$allFragments = ChangelogFragments::load($root . '/' . FRAGMENT_DIR_REL);
$addedFilenames = array_fill_keys(addedFragmentFilenames($root, $mergeBase), true);
$candidateFragments = array_values(array_filter(
    $allFragments,
    static fn(array $fragment): bool => isset($addedFilenames[$fragment['filename']]),
));
$candidateBody = $candidateFragments === [] ? '' : ChangelogFragments::render($candidateFragments);
$candidateChangelog = "## [Unreleased]\n\n" . $candidateBody;
$candidateLines = preg_split('/\n/', $candidateBody) ?: [];
$authorizations = SurfaceChangeAuthorization::parse($candidateChangelog, $candidateLines);
info("Comparing composed declarations with merge base {$mergeBase} ({$baseRef}).");

$problems = [];

foreach ($authorizations['errors'] as $authorizationError) {
    $problems[] = "invalid current-change changelog-fragment public-surface authorization: {$authorizationError}";
}

$unauthorizedRemovals = [];
$invalidRenames = [];
foreach (SurfaceChangeAuthorization::removedMapEntries($baseMap, $headMap) as $fqcn) {
    if (isset($authorizations['removals'][$fqcn])) {
        continue;
    }
    $renameTarget = $authorizations['renames'][$fqcn] ?? null;
    if ($renameTarget === null) {
        $unauthorizedRemovals[] = $fqcn;
        continue;
    }
    if (!isset($headMap[$renameTarget]) || !surfaceTypeExists($renameTarget)) {
        $invalidRenames[] = "{$fqcn} -> {$renameTarget} (replacement must be declared and loadable)";
    }
}
if ($unauthorizedRemovals !== []) {
    $problems[] = count($unauthorizedRemovals) . " governed declaration(s) were removed without a newly-added exact-FQCN "
        . "changelog-fragment authorization of type removed:\n  "
        . implode("\n  ", $unauthorizedRemovals);
}
if ($invalidRenames !== []) {
    $problems[] = count($invalidRenames) . " public-surface rename authorization(s) have no declared, loadable replacement:\n  "
        . implode("\n  ", $invalidRenames);
}

$unauthorizedDowngrades = array_values(array_filter(
    SurfaceChangeAuthorization::publicDowngrades($baseMap, $headMap),
    static fn(string $fqcn): bool => !isset($authorizations['deprecations'][$fqcn]),
));
if ($unauthorizedDowngrades !== []) {
    $problems[] = count($unauthorizedDowngrades) . " public disposition(s) were downgraded without a newly-added exact-FQCN "
        . "changelog-fragment authorization of type deprecated:\n  "
        . implode("\n  ", $unauthorizedDowngrades);
}

// ---------------------------------------------------------------------------
// Phase 3: §6 tracked/generated boundary — each aggregate must be either the
// merge base's tracked bytes, or a fresh generation from HEAD's declarations.
// ---------------------------------------------------------------------------

try {
    $layerByShort = SurfaceMapView::layerTable($root);
    [$freshPhp, $freshMd] = SurfaceMapView::render($headMap, $headDeclarations->packages(), $layerByShort, $scanner);
} catch (\Throwable $e) {
    fail('infrastructure failure while regenerating the aggregate views: ' . $e->getMessage(), 2);
}

foreach ([
    [SURFACE_MAP_PHP_REL, $freshPhp],
    [SURFACE_MAP_MD_REL, $freshMd],
] as [$relativePath, $fresh]) {
    $trackedPath = $root . '/' . $relativePath;
    if (!is_file($trackedPath)) {
        $problems[] = "{$relativePath} does not exist. It is a generated view — run `php bin/generate-surface-map --write`.";
        continue;
    }
    $tracked = (string) file_get_contents($trackedPath);
    if ($tracked === $fresh) {
        continue;
    }
    [$baseBytes, $baseBytesExit] = surfaceGit($root, ['show', $mergeBase . ':' . $relativePath]);
    if ($baseBytesExit === 0 && $tracked === $baseBytes) {
        continue;
    }
    $problems[] = "{$relativePath} is neither byte-identical to the merge base ({$mergeBase}) nor to a fresh generation from HEAD's "
        . 'declarations — it looks hand-edited. Run `php bin/generate-surface-map --write` (docs/specs/public-surface-declarations.md §6).';
}

if ($problems !== []) {
    fail(implode("\n\n", $problems));
}

info('OK — public-surface parity verified: declarations valid, authorization current, aggregates within the tracked/generated boundary.');
exit(0);
