<?php

declare(strict_types=1);

/**
 * Public-surface-map parity check.
 *
 * Enforces docs/specs/stability-charter.md §8.1 / §2.5. Run by
 * .github/workflows/surface-parity.yml and runnable locally:
 *
 *   php tools/check-surface-parity.php --base=origin/main
 *
 * Contract (the AST gate for tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php
 * — same scope and semantics, but a real php-parser walk instead of a single-match-
 * per-file regex, so it sees every declaration, not just the first):
 *
 *   A "public element" is an interface, abstract class, trait, or enum declared
 *   under a package's `src/` tree (concrete `final`/plain classes are
 *   implementations, not contracts, and are intentionally NOT tracked). The single
 *   source of truth for their disposition is docs/public-surface-map.php
 *   (`FQCN => public|internal|extract|remove`).
 *
 *   source -> map : every discovered public element MUST have a map entry, else
 *                   "untracked surface" (a public contract shipped without a
 *                   stability disposition — charter §2.4 forbids indefinite
 *                   ambiguity).
 *   map -> source : every map FQCN MUST resolve to a loadable type. A map entry
 *                   removed relative to the merge base requires a newly-added,
 *                   exact-FQCN authorization in a newly added validated
 *                   changelog fragment. This delta check also governs concrete
 *                   final classes already recorded in the map.
 *
 * This replaces the 2026-05-11 skeleton, which stubbed the scan and so could not
 * detect drift (audit finding C-14). The workflow's `continue-on-error` is removed
 * in the same change: the gate now blocks.
 *
 * Exit codes:
 *   0 — parity verified, no drift
 *   1 — drift detected (untracked element, or removal-without-deprecation)
 *   2 — infrastructure failure (map missing/ill-formed, parser unavailable, parse error)
 */

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Waaseyaa\Tooling\SurfaceChangeAuthorization;
use Waaseyaa\Tooling\ChangelogFragments;

$root = dirname(__DIR__);

$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "surface-parity: vendor/autoload.php not found — run `composer install` first.\n");
    exit(2);
}
require $autoload;

if (!class_exists(ParserFactory::class)) {
    fwrite(STDERR, "surface-parity: nikic/php-parser is not installed.\n");
    exit(2);
}

const SURFACE_MAP_REL = 'docs/public-surface-map.php';
const FRAGMENT_DIR_REL = 'changes/unreleased';

require_once __DIR__ . '/lib/SurfaceChangeAuthorization.php';
require_once __DIR__ . '/lib/ChangelogFragments.php';

/** Public element shapes — contracts/extension points, never concrete classes. */
const PUBLIC_SHAPES = ['interface', 'abstract', 'trait', 'enum'];

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

/** @return array<string, string> */
function loadBaseSurfaceMap(string $root, string $mergeBase): array
{
    [$source, $exitCode] = surfaceGit($root, ['show', $mergeBase . ':' . SURFACE_MAP_REL]);
    if ($exitCode !== 0 || $source === '') {
        fail("cannot read " . SURFACE_MAP_REL . " at merge base {$mergeBase}: " . trim($source), 2);
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
    if (!is_array($map)) {
        fail("merge-base " . SURFACE_MAP_REL . ' must return an array.', 2);
    }

    return $map;
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
// Phase 1: Load the surface map (single source of truth).
// ---------------------------------------------------------------------------

$mapPath = $root . '/' . SURFACE_MAP_REL;
if (!is_file($mapPath)) {
    fail(SURFACE_MAP_REL . ' not found. Per charter §2.5 it is the single source of truth for the public surface.', 2);
}

/** @var mixed $surfaceMap */
$surfaceMap = require $mapPath;
if (!is_array($surfaceMap) || $surfaceMap === []) {
    fail(SURFACE_MAP_REL . ' must return a non-empty array (FQCN => disposition).', 2);
}

$allowedDispositions = ['public', 'internal', 'extract', 'remove'];
$badDispositions = [];
$validatedSurfaceMap = [];
foreach ($surfaceMap as $fqcn => $disposition) {
    if (!is_string($fqcn) || !in_array($disposition, $allowedDispositions, true)) {
        $badDispositions[] = is_string($fqcn) ? "{$fqcn} => " . var_export($disposition, true) : '(non-string key)';
        continue;
    }
    $validatedSurfaceMap[$fqcn] = $disposition;
}
if ($badDispositions !== []) {
    fail(
        "invalid disposition(s) in " . SURFACE_MAP_REL . " (allowed: " . implode('|', $allowedDispositions) . "):\n  "
        . implode("\n  ", $badDispositions),
        2,
    );
}
$surfaceMap = $validatedSurfaceMap;

info('Loaded ' . count($surfaceMap) . ' entries from ' . SURFACE_MAP_REL . '.');

[$mergeBase, $mergeBaseExit] = surfaceGit($root, ['merge-base', 'HEAD', $baseRef]);
$mergeBase = trim($mergeBase);
if ($mergeBaseExit !== 0 || $mergeBase === '') {
    fail("cannot resolve merge base between HEAD and {$baseRef}; fetch the base ref or pass --base=<ref>.", 2);
}
$baseSurfaceMap = loadBaseSurfaceMap($root, $mergeBase);
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
info("Comparing governed map changes with merge base {$mergeBase} ({$baseRef}).");

// ---------------------------------------------------------------------------
// Phase 2: AST-scan src/ for declared public elements (contracts/extension points).
// ---------------------------------------------------------------------------

$scanDirs = [];
$packageDirectories = glob($root . '/packages/*', GLOB_ONLYDIR);
foreach ($packageDirectories === false ? [] : $packageDirectories as $pkg) {
    if (is_dir("{$pkg}/src")) {
        $scanDirs[] = "{$pkg}/src";
    }
}
if (is_dir($root . '/src')) {
    $scanDirs[] = $root . '/src';
}

$parser = (new ParserFactory())->createForNewestSupportedVersion();

$collector = new class extends NodeVisitorAbstract {
    public string $ns = '';
    /** @var array<string, true> fqcn => true (public-shape elements only) */
    public array $elements = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->ns = $node->name !== null ? $node->name->toString() : '';

            return null;
        }

        $isPublicShape = $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Enum_
            || ($node instanceof Node\Stmt\Class_ && $node->name !== null && $node->isAbstract());

        if ($isPublicShape && isset($node->name)) {
            $fqcn = ($this->ns !== '' ? $this->ns . '\\' : '') . $node->name->toString();
            $this->elements[$fqcn] = true;
        }

        return null;
    }
};

/** @var array<string, true> $publicElements */
$publicElements = [];
$fileCount = 0;
foreach ($scanDirs as $dir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $fileCount++;
        try {
            $ast = $parser->parse((string) file_get_contents($file->getPathname()));
        } catch (\Throwable $e) {
            fail("parse error in {$file->getPathname()}: {$e->getMessage()}", 2);
        }
        if ($ast === null) {
            continue;
        }
        $visitor = clone $collector;
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
        foreach ($visitor->elements as $fqcn => $_) {
            $publicElements[$fqcn] = true;
        }
    }
}

info("Scanned {$fileCount} src files across " . count($scanDirs) . ' package trees: '
    . count($publicElements) . ' public-shape elements (interface/abstract/trait/enum).');

// ---------------------------------------------------------------------------
// Phase 3: Compare in both directions.
// ---------------------------------------------------------------------------

$problems = [];

// source -> map : a discovered public element with no disposition.
$untracked = array_values(array_diff(array_keys($publicElements), array_keys($surfaceMap)));
sort($untracked);
if ($untracked !== []) {
    $problems[] = count($untracked) . " untracked public element(s) — declared in source but absent from "
        . SURFACE_MAP_REL . " (add each with `public` or `internal`):\n  " . implode("\n  ", $untracked);
}

// map -> source : every current map entry must still load. Authorization is
// deliberately not an escape hatch for leaving a stale entry behind.
$staleMapEntries = [];
foreach (array_keys($surfaceMap) as $fqcn) {
    if (!surfaceTypeExists($fqcn)) {
        $staleMapEntries[] = $fqcn;
    }
}
sort($staleMapEntries);
if ($staleMapEntries !== []) {
    $problems[] = count($staleMapEntries) . " current map entry(ies) reference types that no longer load. Remove the "
        . "map entry in the same change and add the exact governed Removed fragment authorization:\n  "
        . implode("\n  ", $staleMapEntries);
}

foreach ($authorizations['errors'] as $authorizationError) {
    $problems[] = "invalid current-change changelog-fragment public-surface authorization: {$authorizationError}";
}

// base map -> candidate map : catches removal even when both the source and
// map entry disappear, including concrete final classes the source scanner
// intentionally does not infer as contracts.
$unauthorizedRemovals = [];
$invalidRenames = [];
foreach (SurfaceChangeAuthorization::removedMapEntries($baseSurfaceMap, $surfaceMap) as $fqcn) {
    if (isset($authorizations['removals'][$fqcn])) {
        continue;
    }
    $renameTarget = $authorizations['renames'][$fqcn] ?? null;
    if ($renameTarget === null) {
        $unauthorizedRemovals[] = $fqcn;
        continue;
    }
    if (!isset($surfaceMap[$renameTarget]) || !surfaceTypeExists($renameTarget)) {
        $invalidRenames[] = "{$fqcn} -> {$renameTarget} (replacement must be mapped and loadable)";
    }
}
if ($unauthorizedRemovals !== []) {
    $problems[] = count($unauthorizedRemovals) . " governed map entry(ies) were removed without a newly-added exact-FQCN "
        . "changelog-fragment authorization of type removed:\n  "
        . implode("\n  ", $unauthorizedRemovals);
}
if ($invalidRenames !== []) {
    $problems[] = count($invalidRenames) . " public-surface rename authorization(s) have no mapped, loadable replacement:\n  "
        . implode("\n  ", $invalidRenames);
}

$unauthorizedDowngrades = array_values(array_filter(
    SurfaceChangeAuthorization::publicDowngrades($baseSurfaceMap, $surfaceMap),
    static fn(string $fqcn): bool => !isset($authorizations['deprecations'][$fqcn]),
));
if ($unauthorizedDowngrades !== []) {
    $problems[] = count($unauthorizedDowngrades) . " public disposition(s) were downgraded without a newly-added exact-FQCN "
        . "changelog-fragment authorization of type deprecated:\n  "
        . implode("\n  ", $unauthorizedDowngrades);
}

if ($problems !== []) {
    fail(implode("\n\n", $problems));
}

info('OK — public-surface-map parity verified in both directions.');
exit(0);
