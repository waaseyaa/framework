<?php

declare(strict_types=1);

/**
 * Fresh-install boot probe (#2426).
 *
 * Runs inside a disposable consumer that installed the full framework from
 * path repositories at the exact candidate tree. It performs the real
 * initialization path a new site performs — the explicit schema transition,
 * then an ordinary runtime boot — and asserts the kernel reaches a usable
 * state.
 *
 * The defect this guards: access-policy discovery runs inside
 * AbstractKernel::boot(). If any policy's dependency graph demands state that
 * only exists after configuration is activated, a brand-new install can never
 * boot, because activating configuration itself requires a booted kernel.
 * `Skeleton Smoke (Packaged-form CI)` proves the same property against the
 * published artifact, but only AFTER the tag exists. This probe proves it from
 * source, so a release can be gated on it before anything is published.
 *
 * Exit 0 = a fresh install booted; exit 1 = it did not.
 */

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;

try {
    // A fresh consumer must cross the explicit schema-transition boundary
    // before ordinary runtime boot. Runtime paths deliberately refuse to
    // install schema.
    $schemaKernel = new ConsoleKernel(__DIR__);
    $schemaKernel->bootForSchemaSync();
    $loader = $schemaKernel->getMigrationLoader();
    $schemaKernel->getMigrator()->run($loader->loadAll(), $loader->loadAllV2());
    $manager = $schemaKernel->getEntityTypeManager();
    new EntitySchemaSyncRunner($schemaKernel->getDatabase(), $manager->getFieldRegistry())
        ->run($manager->getDefinitions());

    // No configuration generation has been activated at this point. That is
    // the whole point: a new site has schema and nothing else.
    $kernel = new HttpKernel(__DIR__);
    new ReflectionMethod($kernel, 'boot')->invoke($kernel);

    $entityTypes = $kernel->getEntityTypeManager();
    if (!$entityTypes->hasDefinition('user')) {
        fwrite(STDERR, "::error::fresh-install boot registered no 'user' entity type\n");
        exit(1);
    }

    fwrite(STDOUT, "fresh-install kernel boot OK\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '::error::fresh-install kernel boot FAILED: ' . $e::class . ': ' . $e->getMessage() . "\n");
    for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
        fwrite(STDERR, '  previous: ' . $p::class . ': ' . $p->getMessage() . "\n");
    }
    exit(1);
}
