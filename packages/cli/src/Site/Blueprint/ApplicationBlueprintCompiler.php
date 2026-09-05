<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint;

use Waaseyaa\CLI\Site\Blueprint\Emitter\BlueprintArtifactEmitterInterface;
use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintFieldType;
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
 * without the check. Round-2 review (R2-1, R2-2) found the lexical check
 * alone still let several uncoded PHP fatals through, so the same
 * pre-check additionally refuses, all before any emitter runs: an entity
 * id whose PascalCase form is a reserved PHP class name (`string` ->
 * `String` cannot be declared as a class); two entity ids that PascalCase
 * to the same class name (`blog_post` and `blog__post` both -> `BlogPost`);
 * an enum value whose PascalCase form is not a valid PHP identifier
 * (`"in progress"`), *is* the reserved case name `class`, or collides with
 * another declared value's case name after conversion (`draft` and
 * `Draft`); and two entity/field id pairs whose generated enum class short
 * name (`<PascalCase(entity)><PascalCase(field)>`) collides.
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
     * Names PHP refuses to declare a class, interface, trait, or enum as —
     * the full reserved-keyword table plus the "other reserved words" the
     * PHP manual lists as additionally forbidden in a class-name position
     * (`int`, `string`, `never`, `parent`, `self`, ...). Checked
     * case-insensitively, matching PHP's own keyword matching: `String` is
     * exactly as reserved as `string`.
     *
     * @var list<string>
     */
    private const array RESERVED_CLASS_NAMES = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone',
        'const', 'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty',
        'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'exit',
        'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
        'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset',
        'list', 'match', 'namespace', 'new', 'or', 'print', 'private', 'protected', 'public',
        'readonly', 'require', 'require_once', 'return', 'static', 'switch', 'throw', 'trait',
        'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
        'int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object',
        'mixed', 'never', 'self', 'parent', 'resource', 'numeric',
    ];

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
     *
     * Round-2 review (R2-1, R2-2) found the lexical check alone was not
     * enough: an id can be grammar-valid and still PascalCase into something
     * PHP cannot declare as a class (a reserved word), or into the same
     * class name as another entity, or (for an `enum` field's declared
     * values) into an invalid or colliding enum case name. All of that is
     * checked here too, over the same violation list, so a single refusal
     * carries every offense the blueprint contains rather than stopping at
     * the first `EntityClassEmitter` happens to reach.
     */
    private static function assertPhpIdentifierGrammar(ApplicationBlueprint $blueprint): void
    {
        $violations = [];
        $classNamesSeen = [];
        $enumClassNamesSeen = [];
        foreach ($blueprint->entities as $entity) {
            $entityPointer = "/application_blueprint/entities/{$entity->id}/id";
            self::checkIdentifier($entity->id, $entityPointer, $violations);

            $entityClassName = self::pascalCase($entity->id);
            self::checkClassName($entityClassName, $entityPointer, "entity id \"{$entity->id}\"", $violations);
            self::checkClassNameCollision($entityClassName, $entityPointer, "entity id \"{$entity->id}\"", $classNamesSeen, $violations);

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
                $fieldPointer = "/application_blueprint/entities/{$entity->id}/fields/{$field->id}/id";
                self::checkIdentifier($field->id, $fieldPointer, $violations);

                if ($field->type === BlueprintFieldType::Enum) {
                    $enumClassName = $entityClassName . self::pascalCase($field->id);
                    self::checkClassName($enumClassName, $fieldPointer, "enum field \"{$entity->id}.{$field->id}\"", $violations);
                    self::checkClassNameCollision($enumClassName, $fieldPointer, "enum field \"{$entity->id}.{$field->id}\"", $enumClassNamesSeen, $violations);
                    self::checkEnumCaseNames($field->values ?? [], "/application_blueprint/entities/{$entity->id}/fields/{$field->id}/values", $violations);
                }
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

    /** @param list<GenerationViolation> $violations */
    private static function checkClassName(string $className, string $pointer, string $subject, array &$violations): void
    {
        if (!\in_array(strtolower($className), self::RESERVED_CLASS_NAMES, true)) {
            return;
        }

        $violations[] = new GenerationViolation(
            GenerationErrorCode::MaliciousIdentifier,
            "Blueprint {$subject} PascalCases to \"{$className}\", a reserved PHP word that cannot be declared as a class.",
            pointer: $pointer,
        );
    }

    /**
     * @param array<string, string> $seen lowercased class name => the subject that first claimed it (mutated)
     * @param list<GenerationViolation> $violations
     */
    private static function checkClassNameCollision(string $className, string $pointer, string $subject, array &$seen, array &$violations): void
    {
        $key = strtolower($className);
        if (isset($seen[$key])) {
            $violations[] = new GenerationViolation(
                GenerationErrorCode::MaliciousIdentifier,
                "Blueprint {$subject} PascalCases to \"{$className}\", the same generated class name as {$seen[$key]}.",
                pointer: $pointer,
            );

            return;
        }
        $seen[$key] = $subject;
    }

    /**
     * An `enum` field's declared `values` are free-form non-empty strings
     * (`ApplicationBlueprintParser`), not identifiers. `EntityClassEmitter`
     * converts each to a PHP enum case name best-effort (`pascalCase`); this
     * mirrors that conversion to refuse, before any emitter runs, a value
     * that cannot become a valid case name, that becomes the reserved case
     * name `class` (`A class constant must not be called 'class'`), or that
     * collides with another declared value's case name after conversion
     * (`draft` and `Draft` both -> `Draft`).
     *
     * @param list<string> $values
     * @param list<GenerationViolation> $violations
     */
    private static function checkEnumCaseNames(array $values, string $pointer, array &$violations): void
    {
        $caseNamesSeen = [];
        foreach ($values as $index => $value) {
            $valuePointer = $pointer . '/' . $index;
            $caseName = self::pascalCase($value);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $caseName) !== 1) {
                $violations[] = new GenerationViolation(
                    GenerationErrorCode::MaliciousIdentifier,
                    "Blueprint enum value \"{$value}\" is not representable as a PHP enum case name.",
                    pointer: $valuePointer,
                );
                continue;
            }
            if (strtolower($caseName) === 'class') {
                $violations[] = new GenerationViolation(
                    GenerationErrorCode::MaliciousIdentifier,
                    "Blueprint enum value \"{$value}\" PascalCases to the reserved enum case name \"class\".",
                    pointer: $valuePointer,
                );
                continue;
            }
            $key = strtolower($caseName);
            if (isset($caseNamesSeen[$key])) {
                $violations[] = new GenerationViolation(
                    GenerationErrorCode::MaliciousIdentifier,
                    "Blueprint enum value \"{$value}\" PascalCases to \"{$caseName}\", the same case name as \"{$caseNamesSeen[$key]}\".",
                    pointer: $valuePointer,
                );
                continue;
            }
            $caseNamesSeen[$key] = $value;
        }
    }

    /**
     * Duplicated from `EntityClassEmitter::pascalCase()` /
     * `ProviderRegistrationEmitter::pascalCase()` rather than shared: each
     * copy is a private implementation detail of pure string formatting, not
     * a public seam, and the three call sites already carried this exact
     * duplication before this pre-check existed. Keep the algorithm
     * identical across all three if it ever changes — that identity is what
     * makes this pre-check a valid predictor of what the emitters will
     * later produce.
     */
    private static function pascalCase(string $id): string
    {
        return str_replace('_', '', ucwords(str_replace('-', '_', $id), '_'));
    }
}
