<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint;

use Waaseyaa\CLI\Site\Blueprint\Emitter\BlueprintArtifactEmitterInterface;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\GeneratorFeatureNegotiation;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifest;

/**
 * The pure blueprint root compiler (FW-SITE-BLUEPRINT-01D, #2787, decision (a)).
 *
 * Composes an injected {@see SiteArtifactRenderer}'s rendering of the
 * manifest with the ordered emitter roster's blueprint artifacts, publishing
 * the SAME root `site` unit (`managed`, ADR-025 D-10.1) the manifest renderer
 * publishes — but under its own `generator.fqcn`, so the engine's eligibility
 * gate (ADR-025 D-13, added in 01D-2) can tell the two compilers apart.
 *
 * `set_evolution` is `additive`, declared purely: eligibility is entirely the
 * execution authority's decision (D-13 item 1), never this compiler's. In
 * 01D-1 that authority's closed list (`SiteInitializationService::
 * ADDITIVE_COMPILERS`) does not yet name this class, so every plan this
 * compiler produces is refused `GEN011_UNAUTHORIZED_SET_DELTA` on evaluation
 * — by design (`GenerationBlueprintAdmissionTest`).
 *
 * `compile()` takes no receipt and needs no approval: parsing, negotiation
 * and compilation are approval-free (ADR-023 D-4); the receipt is an input to
 * the execution authority 01D-2 adds, never to this pure function.
 *
 * Not a {@see \Waaseyaa\SiteContract\Generation\SiteRecipeRendererInterface}
 * and never registered in `SiteArtifactRendererFactory`: a recipe renderer's
 * output is rendered under `SiteArtifactRenderer::class`, whose plan already
 * declares `additive` and is already on `ADDITIVE_COMPILERS` — inheriting
 * that grant would evolve the root set with no approval check, bypassing
 * D-13.
 *
 * @api
 */
final class ApplicationBlueprintCompiler
{
    /**
     * The generator-feature tokens this compiler advertises (ADR-023 D-2,
     * decision (g)). 01D-2 unions this roster into
     * `SiteArtifactRendererFactory::advertisedGeneratorFeatures()` when it
     * wires the compiler into `site:init`.
     *
     * @var list<string>
     */
    public const array GENERATOR_FEATURES = ['site-application-blueprint-v1'];

    private const string METADATA_PATH = '.waaseyaa/generated.json';

    /** @param list<BlueprintArtifactEmitterInterface> $emitters a fixed, ordered roster */
    public function __construct(
        private readonly SiteArtifactRenderer $renderer,
        private readonly array $emitters = [],
    ) {}

    public function compile(SiteManifest $manifest): ArtifactPlan
    {
        $blueprint = $manifest->applicationBlueprint;
        if ($blueprint === null) {
            throw new \InvalidArgumentException('ApplicationBlueprintCompiler requires a manifest declaring an application_blueprint section.');
        }
        GeneratorFeatureNegotiation::assert($manifest, self::GENERATOR_FEATURES, self::class);

        $baseArtifacts = array_values(array_filter(
            $this->renderer->render($manifest)->artifacts,
            static fn(GeneratedArtifact $artifact): bool => $artifact->path !== self::METADATA_PATH,
        ));
        $basePaths = array_map(static fn(GeneratedArtifact $artifact): string => $artifact->path, $baseArtifacts);

        $seenEmitterIds = [];
        $allArtifacts = $baseArtifacts;
        $seenPaths = array_flip($basePaths);
        $registrations = [];
        $companionTests = [];
        foreach ($this->emitters as $emitter) {
            $emitterId = $emitter->id();
            if ($emitterId === '' || isset($seenEmitterIds[$emitterId])) {
                throw new \InvalidArgumentException("Duplicate or empty blueprint emitter identity: {$emitterId}");
            }
            $seenEmitterIds[$emitterId] = true;

            $emission = $emitter->emit($blueprint, $manifest);
            foreach ($emission->artifacts as $artifact) {
                if (isset($seenPaths[$artifact->path])) {
                    throw new \InvalidArgumentException("Blueprint emitter \"{$emitterId}\" produced a path already claimed by another emitter or the base artifact set: {$artifact->path}");
                }
                $seenPaths[$artifact->path] = true;
                $allArtifacts[] = $artifact;
            }
            foreach ($emission->registrations as $registration) {
                $registrations[] = $registration;
            }
            foreach ($emission->companionTests as $companionTest) {
                $companionTests[] = $companionTest;
            }
        }

        usort($allArtifacts, static fn(GeneratedArtifact $left, GeneratedArtifact $right): int => strcmp($left->path, $right->path));
        usort($registrations, static function (ComposerProviderRegistration $left, ComposerProviderRegistration $right): int {
            $byFqcn = strcmp($left->fqcn, $right->fqcn);

            return $byFqcn !== 0 ? $byFqcn : strcmp((string) $left->group, (string) $right->group);
        });
        $companionTests = array_values(array_unique($companionTests));
        sort($companionTests, SORT_STRING);

        return new ArtifactPlan(
            self::class,
            $manifest->generatorVersion,
            'site',
            GenerationUnitDisposition::Managed,
            $manifest->digest,
            $allArtifacts,
            registrations: $registrations,
            companionTests: $companionTests,
            setEvolution: ArtifactSetEvolution::Additive,
        );
    }
}
