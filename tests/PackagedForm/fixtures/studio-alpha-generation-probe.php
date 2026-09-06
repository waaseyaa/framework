<?php

declare(strict_types=1);

/**
 * In-consumer generation probe for the Studio-alpha acceptance harness (#2789).
 *
 * Copied into the artifact-installed consumer and run from THAT root, so every
 * class it touches is installed archive bytes. It never reaches into the
 * candidate checkout, and it renders nothing the shipped compiler would not:
 * its whole job is to hand the execution authority a reviewed request document
 * the way a studio process would, then get out of the way.
 *
 * Usage (from the consumer root):
 *   php studio-alpha-generation-probe.php emit-request  MANIFEST OUT.json
 *   php studio-alpha-generation-probe.php emit-set-delta MANIFEST OUT.json
 */

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Generation\ArtifactApplyRequest;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

$mode = $argv[1] ?? '';
$manifestPath = $argv[2] ?? '';
$outPath = $argv[3] ?? '';
$root = __DIR__;

if (!in_array($mode, ['emit-request', 'emit-set-delta'], true) || $manifestPath === '' || $outPath === '') {
    fwrite(STDERR, "usage: studio-alpha-generation-probe.php emit-request|emit-set-delta MANIFEST OUT\n");
    exit(2);
}

$manifest = new SiteManifestParser()->parse((string) file_get_contents($manifestPath), $manifestPath);
$site = new SiteArtifactRenderer()->render($manifest);

/** The root unit exactly as the shipped initializer composes it. */
$rootPlan = static fn(string $generatorFqcn, ArtifactSetEvolution $evolution): ArtifactPlan => new ArtifactPlan(
    $generatorFqcn,
    $site->generatorVersion,
    'site',
    GenerationUnitDisposition::Managed,
    $site->manifestDigest,
    array_values(array_filter(
        $site->artifacts,
        static fn(GeneratedArtifact $artifact): bool => $artifact->path !== '.waaseyaa/generated.json',
    )),
    setEvolution: $evolution,
);

$service = new SiteInitializationService($root);
$eligible = $rootPlan(SiteArtifactRenderer::class, ArtifactSetEvolution::Additive);

// The project-state digest is always observed through the eligible plan. For
// the refusal probe that is the point: the request is well-formed and current,
// so the refusal that follows can only be the admission decision itself, never
// a stale-plan accident.
$projectStateDigest = $service->evaluate($eligible)->projectStateDigest;

$plan = $mode === 'emit-request'
    ? $eligible
    // A compiler that is not on the closed eligibility list, declaring that it
    // may evolve the root unit's artifact set. ADR-025 D-2.3a says a compiler
    // cannot assert its own eligibility; the authority must refuse GEN011.
    : $rootPlan('Studio\\Alpha\\UneligibleCompiler', ArtifactSetEvolution::Additive);

file_put_contents(
    $outPath,
    new ArtifactApplyRequest($plan, $plan->digest, $projectStateDigest)->canonicalJson() . "\n",
);

printf("%s %s\n", $mode, $plan->digest);
