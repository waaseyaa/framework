<?php

declare(strict_types=1);

/**
 * Fresh-install boot probe (#2426).
 *
 * Runs inside a disposable consumer that installed the full framework from
 * path repositories at the exact candidate tree, and that has already completed
 * the governed installation phase (`waaseyaa install:init`). It asserts the
 * kernel then reaches a usable state through ordinary runtime boot alone.
 *
 * The defect this guards: access-policy discovery runs inside
 * AbstractKernel::boot(). If any policy's dependency graph demands state that
 * only exists after configuration is activated, a brand-new install can never
 * boot, because activating configuration itself requires a booted kernel.
 * `Skeleton Smoke (Packaged-form CI)` proves the same property against the
 * published artifact, but only AFTER the tag exists. This probe proves it from
 * source, so a release can be gated on it before anything is published.
 *
 * The probe deliberately goes past boot to a real entity round-trip. Booting is
 * necessary but not sufficient: v0.1.0-alpha.296 booted a fresh install
 * correctly and still could not save, because the PRE_SAVE listener
 * WorkflowStateGuard resolves workflow configuration, which reads the active
 * generation. An earlier revision of this probe stopped at boot, so the release
 * gate went green over exactly that failure while Skeleton Smoke — which does
 * save — went red. Whatever a consumer must do on day one, this proves.
 *
 * Exit 0 = a fresh install booted AND completed a write; exit 1 = it did not.
 */

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\User;

try {
    // The governed installation phase has already run (the harness invoked
    // `waaseyaa install:init`, exactly as a real deployment does). This probe
    // proves what a consumer gets AFTER it: an ordinary runtime boot and a
    // day-one write, with no privileged setup of its own.
    $kernel = new HttpKernel(__DIR__);
    new ReflectionMethod($kernel, 'boot')->invoke($kernel);

    $entityTypes = $kernel->getEntityTypeManager();
    if (!$entityTypes->hasDefinition('user')) {
        fwrite(STDERR, "::error::fresh-install boot registered no 'user' entity type\n");
        exit(1);
    }

    fwrite(STDOUT, "fresh-install kernel boot OK\n");

    // Day-one write. This is the step that catches a fresh install which boots
    // but cannot persist anything — the PRE_SAVE path resolves workflow
    // configuration, so it exercises configuration reads that boot alone does
    // not reach.
    $marker = 'fresh-install-' . bin2hex(random_bytes(4));
    $repository = $entityTypes->getRepository('user');
    $uid = $repository->save(User::make([
        'name' => $marker,
        'mail' => $marker . '@example.test',
        'permissions' => ['access user profiles'],
        'status' => 1,
        'created' => time(),
    ]));

    if ($uid <= 0) {
        fwrite(STDERR, "::error::fresh-install save produced no uid (got {$uid})\n");
        exit(1);
    }

    $reloaded = $repository->find((string) $uid);
    if (!$reloaded instanceof User) {
        fwrite(STDERR, '::error::fresh-install reload returned ' . get_debug_type($reloaded) . ", not User\n");
        exit(1);
    }

    fwrite(STDOUT, "fresh-install entity round-trip OK (uid={$uid})\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '::error::fresh-install kernel boot FAILED: ' . $e::class . ': ' . $e->getMessage() . "\n");
    for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
        fwrite(STDERR, '  previous: ' . $p::class . ': ' . $p->getMessage() . "\n");
    }
    exit(1);
}
