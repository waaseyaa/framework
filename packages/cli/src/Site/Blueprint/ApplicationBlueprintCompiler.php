<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint;

use Waaseyaa\CLI\Site\Blueprint\Emitter\BlueprintArtifactEmitterInterface;
use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;
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
 * Before invoking any emitter, `compile()` also asserts every blueprint
 * entity/field/relationship id headed for a PHP identifier position is a
 * valid one (`GEN006_MALICIOUS_IDENTIFIER`) — the SITE0xx grammar admits a
 * hyphen that PHP cannot represent as a class, property, or entity-key
 * name, and review found this can only be reached by an uncoded exception
 * without the check.
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
        self::assertPhpIdentifierGrammar($blueprint);

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
        usort($registrations, self::compareRegistrations(...));
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

    /**
     * Same ordering `ArtifactPlan::compareRegistrations()` enforces
     * (`null` group first, then string groups by `strcmp`) — replicated
     * here rather than exposed from `ArtifactPlan` because that comparator
     * is a private implementation detail of the plan's own constructor
     * invariant, not a public seam. Sorting by `(string) $group` instead
     * would treat `null` and `''` as equal and could hand `ArtifactPlan` a
     * registration list it rejects as unsorted.
     */
    private static function compareRegistrations(
        ComposerProviderRegistration $left,
        ComposerProviderRegistration $right,
    ): int {
        $byFqcn = strcmp($left->fqcn, $right->fqcn);
        if ($byFqcn !== 0) {
            return $byFqcn;
        }
        if ($left->group === $right->group) {
            return 0;
        }
        if ($left->group === null) {
            return -1;
        }
        if ($right->group === null) {
            return 1;
        }

        return strcmp($left->group, $right->group);
    }

    /**
     * Blueprint entity/field ids are validated only against the SITE0xx
     * grammar (`^[a-z][a-z0-9_-]*$`, `ManifestShapeReader::id()`), which
     * permits a hyphen — not a valid PHP identifier character. An id headed
     * for a PHP class name, property name, or entity-key value would either
     * be silently rewritten by an emitter (risking a namespace collision the
     * validator never checked for) or crash deep inside one with an uncoded
     * exception. Refuse once, here, before any emitter runs, with the coded
     * `GEN006_MALICIOUS_IDENTIFIER` id ADR-025 D-5 already reserves for "a
     * unit id fails the D-2.1 grammar" — the same shape of problem one layer
     * up the blueprint's own ids.
     */
    private static function assertPhpIdentifierGrammar(ApplicationBlueprint $blueprint): void
    {
        $violations = [];
        foreach ($blueprint->entities as $entity) {
            self::checkIdentifier($entity->id, "/application_blueprint/entities/{$entity->id}/id", $violations);

            $keys = [
                'id' => $entity->keys->id,
                'uuid' => $entity->keys->uuid,
                'revision' => $entity->keys->revision,
                'langcode' => $entity->keys->langcode,
                'default_langcode' => $entity->keys->defaultLangcode,
            ];
            foreach ($keys as $keyName => $value) {
                if ($value !== null) {
                    self::checkIdentifier($value, "/application_blueprint/entities/{$entity->id}/keys/{$keyName}", $violations);
                }
            }

            foreach ($entity->fields as $field) {
                self::checkIdentifier($field->id, "/application_blueprint/entities/{$entity->id}/fields/{$field->id}/id", $violations);
            }
        }
        foreach ($blueprint->relationships as $relationship) {
            self::checkIdentifier($relationship->fromField, "/application_blueprint/relationships/{$relationship->id}/from/field", $violations);
        }

        if ($violations !== []) {
            throw new GenerationRefusalException(self::class, $violations);
        }
    }

    /** @param list<GenerationViolation> $violations */
    private static function checkIdentifier(string $id, string $pointer, array &$violations): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $id) === 1) {
            return;
        }

        $violations[] = new GenerationViolation(
            GenerationErrorCode::MaliciousIdentifier,
            "Blueprint identifier is not representable as a PHP identifier segment: {$id}",
            pointer: $pointer,
        );
    }
}
