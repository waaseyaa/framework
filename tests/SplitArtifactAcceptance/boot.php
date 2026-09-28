<?php

declare(strict_types=1);

/**
 * Split-artifact bootstrap probe (#2649).
 *
 * Runs inside a consumer whose entire waaseyaa/* graph was installed from a
 * local Composer ARTIFACT repository — extracted zip bytes, no path
 * repository, no symlink back into the checkout — and that has already
 * completed the governed installation phase (`waaseyaa install:init`).
 *
 * It is deliberately narrower than tests/FreshInstallBoot/boot.php. That probe
 * (#2426) owns the fresh-install boot property and the auth-extension
 * composition, and it proves them against a PATH-resolved consumer. Repeating
 * its assertions here would double the maintenance for no new information: the
 * only thing this consumer changes is how the bytes arrived. So this probe
 * asserts exactly the property that packaging can break — the kernel boots and
 * a day-one write completes when every class is loaded out of an extracted
 * archive rather than out of the source tree.
 *
 * The same probe is run against the --no-dev consumer. That is the alpha.106 ->
 * alpha.107 outage class: a class under src/ extending a dev-only symbol boots
 * fine with dev dependencies present and takes the consumer's kernel down when
 * they are not, because PackageManifestCompiler reflection-loads it.
 *
 * Exit 0 = booted and wrote; exit 1 = it did not.
 */

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\User;

try {
    $kernel = new HttpKernel(__DIR__);
    new ReflectionMethod($kernel, 'boot')->invoke($kernel);

    $entityTypes = $kernel->getEntityTypeManager();
    if (!$entityTypes->hasDefinition('user')) {
        fwrite(STDERR, "::error::split-artifact boot registered no 'user' entity type\n");
        exit(1);
    }

    fwrite(STDOUT, "split-artifact kernel boot OK\n");

    if (!interface_exists('Waaseyaa\\AI\\Vector\\EmbeddingStorageInterface')) {
        $server = $_SERVER;
        $get = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/search?q=absent&type=user';
        $_SERVER['QUERY_STRING'] = 'q=absent&type=user';
        $_GET = ['q' => 'absent', 'type' => 'user'];
        try {
            $response = $kernel->handle();
        } finally {
            $_SERVER = $server;
            $_GET = $get;
        }
        if ($response->getStatusCode() !== 501) {
            fwrite(STDERR, "::error::split-artifact consumer without ai-vector did not return 501 for semantic search\n");
            exit(1);
        }
        fwrite(STDOUT, "split-artifact ai-vector absence OK (search 501)\n");
    }

    $marker = 'split-artifact-' . bin2hex(random_bytes(4));
    $repository = $entityTypes->getRepository('user');
    $uid = $repository->save(User::make([
        'name' => $marker,
        'mail' => $marker . '@example.test',
        'permissions' => ['access user profiles'],
        'status' => 1,
        'created' => time(),
    ]));

    if ($uid <= 0) {
        fwrite(STDERR, "::error::split-artifact save produced no uid (got {$uid})\n");
        exit(1);
    }

    $reloaded = $repository->find((string) $uid);
    if (!$reloaded instanceof User) {
        fwrite(STDERR, '::error::split-artifact reload returned ' . get_debug_type($reloaded) . ", not User\n");
        exit(1);
    }

    fwrite(STDOUT, "split-artifact entity round-trip OK (uid={$uid})\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '::error::split-artifact kernel boot FAILED: ' . $e::class . ': ' . $e->getMessage() . "\n");
    for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
        fwrite(STDERR, '  previous: ' . $p::class . ': ' . $p->getMessage() . "\n");
    }
    exit(1);
}
