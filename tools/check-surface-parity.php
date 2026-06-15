<?php

declare(strict_types=1);

/**
 * Public-surface-map parity check.
 *
 * Enforces docs/specs/stability-charter.md §8.1 / §2.5. Run by
 * .github/workflows/surface-parity.yml and runnable locally:
 *
 *   php tools/check-surface-parity.php
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
 *   map -> source : every map FQCN MUST resolve to a loadable type, else
 *                   "removed-without-deprecation" — unless CHANGELOG.md carries a
 *                   removal/deprecation note naming it (charter §4 cycle). Existence
 *                   is autoload-based (class/interface/trait/enum_exists), so map
 *                   entries that live in a package's `testing/` autoload-dev surface
 *                   (the conformance bases) resolve without being scanned as src.
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
const CHANGELOG_REL = 'CHANGELOG.md';

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
foreach ($surfaceMap as $fqcn => $disposition) {
    if (!is_string($fqcn) || !in_array($disposition, $allowedDispositions, true)) {
        $badDispositions[] = is_string($fqcn) ? "{$fqcn} => " . var_export($disposition, true) : '(non-string key)';
    }
}
if ($badDispositions !== []) {
    fail(
        "invalid disposition(s) in " . SURFACE_MAP_REL . " (allowed: " . implode('|', $allowedDispositions) . "):\n  "
        . implode("\n  ", $badDispositions),
        2,
    );
}

info('Loaded ' . count($surfaceMap) . ' entries from ' . SURFACE_MAP_REL . '.');

// ---------------------------------------------------------------------------
// Phase 2: AST-scan src/ for declared public elements (contracts/extension points).
// ---------------------------------------------------------------------------

$scanDirs = [];
foreach (glob($root . '/packages/*', GLOB_ONLYDIR) ?: [] as $pkg) {
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

        if ($isPublicShape && isset($node->name) && $node->name !== null) {
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

// map -> source : a map entry whose type no longer loads, with a CHANGELOG escape hatch.
$changelog = is_file($root . '/' . CHANGELOG_REL) ? (string) file_get_contents($root . '/' . CHANGELOG_REL) : '';
$removedWithoutNotice = [];
foreach (array_keys($surfaceMap) as $fqcn) {
    if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn)) {
        continue;
    }
    $shortName = (string) substr((string) strrchr($fqcn, '\\'), 1);
    // Accept either the FQCN or its leaf name appearing in the changelog as the
    // documented removal/deprecation note (charter §4).
    if (str_contains($changelog, $fqcn) || ($shortName !== '' && str_contains($changelog, $shortName))) {
        continue;
    }
    $removedWithoutNotice[] = $fqcn;
}
sort($removedWithoutNotice);
if ($removedWithoutNotice !== []) {
    $problems[] = count($removedWithoutNotice) . " map entry(ies) reference types that no longer load and have no "
        . CHANGELOG_REL . " removal note (charter §4 requires a deprecation cycle + `### Removed` entry):\n  "
        . implode("\n  ", $removedWithoutNotice);
}

if ($problems !== []) {
    fail(implode("\n\n", $problems));
}

info('OK — public-surface-map parity verified in both directions.');
exit(0);
