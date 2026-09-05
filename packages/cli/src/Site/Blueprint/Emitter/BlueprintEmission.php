<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint\Emitter;

use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;

/**
 * The immutable output of one {@see BlueprintArtifactEmitterInterface}
 * (FW-SITE-BLUEPRINT-01D decision (f)).
 *
 * Mirrors the shape of the members `ApplicationBlueprintCompiler` composes
 * into its `ArtifactPlan`: generated files, Composer provider registrations,
 * and companion test paths (a subset of this emission's own artifact paths).
 *
 * @api
 */
final readonly class BlueprintEmission
{
    /**
     * @param list<GeneratedArtifact> $artifacts
     * @param list<ComposerProviderRegistration> $registrations
     * @param list<string> $companionTests artifact paths; must be a subset of
     *   this emission's own artifact paths
     */
    public function __construct(
        public array $artifacts,
        public array $registrations = [],
        public array $companionTests = [],
    ) {
        $paths = array_map(static fn(GeneratedArtifact $artifact): string => $artifact->path, $this->artifacts);
        foreach ($this->companionTests as $companionTest) {
            if (!in_array($companionTest, $paths, true)) {
                throw new \InvalidArgumentException("Blueprint emission companion test is not one of its own artifacts: {$companionTest}");
            }
        }
    }
}
