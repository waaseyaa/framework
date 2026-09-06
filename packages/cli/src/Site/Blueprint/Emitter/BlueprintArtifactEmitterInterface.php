<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint\Emitter;

use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\SiteManifest;

/**
 * One pure emitter contributing to the blueprint root compiler's output
 * (FW-SITE-BLUEPRINT-01D decision (f), ADR-025 D-8).
 *
 * An emitter is a pure function of its input: same blueprint and manifest in,
 * byte-identical {@see BlueprintEmission} out, with no filesystem, clock, or
 * environment access. `Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompiler`
 * composes a fixed, ordered list of emitters (registered by
 * `Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory::create()`),
 * asserts every `id()` is unique, and asserts every emitted artifact path is
 * pairwise disjoint across emitters and disjoint from the base manifest-only
 * artifact set — an overlap is a compile-time `\InvalidArgumentException`,
 * never a project-state refusal.
 *
 * @api
 */
interface BlueprintArtifactEmitterInterface
{
    /**
     * A stable, unique identity for this emitter within one compiler
     * composition (used only for the compiler's own duplicate-id check;
     * never persisted).
     */
    public function id(): string;

    public function emit(ApplicationBlueprint $blueprint, SiteManifest $manifest): BlueprintEmission;
}
