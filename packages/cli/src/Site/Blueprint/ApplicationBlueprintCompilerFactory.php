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
 * `site:init` uses this root after generator-feature negotiation; strict
 * `site:doctor` uses the same composition to verify applied artifacts. Both
 * paths leave approval and project observation to the execution authority.
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
