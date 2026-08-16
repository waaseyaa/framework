<?php

declare(strict_types=1);

/**
 * Core-only boot smoke (audit follow-up to C-21).
 *
 * Run from a project that requires ONLY `waaseyaa/core` (installed via a path repository in
 * CI, so it tests the source `core` metapackage with no release lag). It boots the kernel's
 * full boot sequence — the part where `AbstractKernel::bootScheduleEntries()` does an
 * unguarded, fail-closed `new Schedule()` — on the `core` package set alone.
 *
 * We drive `AbstractKernel::boot()` directly via an anonymous subclass rather than a concrete
 * kernel because the concrete kernels sit on layers ABOVE core: `HttpKernel::finalizeBoot()`
 * references `Waaseyaa\Api\Http\DiscoveryApiHandler` (the `api` HTTP layer) and
 * `ConsoleKernel::handle()` builds the Symfony CLI application (the `cli` layer) — neither of
 * which belongs in the minimal engine. This probe asserts the engine itself boots on core.
 *
 * Exit 0 = booted; exit 1 = a core boot dependency is missing from the `core` metapackage
 * (e.g. `waaseyaa/scheduler` — the exact gap this guards against).
 */

require __DIR__ . '/vendor/autoload.php';

final class CoreOnlyBootKernel extends \Waaseyaa\Foundation\Kernel\AbstractKernel
{
    public function bootProbe(): void
    {
        $this->boot();
    }
}

try {
    $schemaKernel = new CoreOnlyBootKernel(__DIR__);
    $schemaKernel->bootForSchemaSync();
    $loader = $schemaKernel->getMigrationLoader();
    $schemaKernel->getMigrator()->run($loader->loadAll(), $loader->loadAllV2());
    $manager = $schemaKernel->getEntityTypeManager();
    new \Waaseyaa\EntityStorage\EntitySchemaSyncRunner(
        $schemaKernel->getDatabase(),
        $manager->getFieldRegistry(),
    )->run($manager->getDefinitions());

    $kernel = new CoreOnlyBootKernel(__DIR__);
    $kernel->bootProbe();
    fwrite(\STDOUT, "core-only kernel boot OK\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(\STDERR, '::error::core-only kernel boot FAILED: ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
