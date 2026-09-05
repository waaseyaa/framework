<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint;

use Waaseyaa\CLI\Site\Blueprint\Emitter\EntityClassEmitter;
use Waaseyaa\CLI\Site\Blueprint\Emitter\ProviderRegistrationEmitter;
use Waaseyaa\CLI\Site\Blueprint\Emitter\RelationshipEmitter;
use Waaseyaa\CLI\Site\SiteArtifactRendererFactory;

/**
 * The single composition root for {@see ApplicationBlueprintCompiler}
 * (FW-SITE-BLUEPRINT-01D decision (f)).
 *
 * #2788 appends its permission, role, policy and workflow emitters here
 * without editing the compiler; 01D-3 appends fixtures and checks the same
 * way.
 *
 * Referenced by no handler, handler-reachable factory or doctor path in
 * 01D-1 (`tests/Architecture/BlueprintCompilerActivationBoundaryTest.php`):
 * the compiler is unreachable from the CLI until 01D-2 wires it into
 * `site:init`/`site:doctor`.
 *
 * @api
 */
final class ApplicationBlueprintCompilerFactory
{
    public static function create(): ApplicationBlueprintCompiler
    {
        return new ApplicationBlueprintCompiler(
            SiteArtifactRendererFactory::create(),
            [
                new EntityClassEmitter(),
                new RelationshipEmitter(),
                new ProviderRegistrationEmitter(),
            ],
        );
    }
}
