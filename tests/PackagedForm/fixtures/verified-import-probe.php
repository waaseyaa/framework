<?php

declare(strict_types=1);

/**
 * What the importing consumer can do after a verified import (#2430).
 *
 * Ordinary runtime boot, the imported configuration readable through the
 * ordinary read API, and a day-one entity write and read back. Booting alone is
 * not enough — v0.1.0-alpha.296 booted a fresh install and could not save.
 */

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Config\Authority\ActiveConfigurationBridgeInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\User;

try {
    $kernel = new HttpKernel(__DIR__);
    new ReflectionMethod($kernel, 'boot')->invoke($kernel);
    fwrite(STDOUT, "consumer ordinary boot OK\n");

    // Read the imported entry back from the ACTIVE store through the runtime
    // authority — never by reading the sync directory, which is authored input
    // and never runtime state.
    $bridge = null;
    foreach ($kernel->getProviders() as $provider) {
        if (isset($provider->getBindings()[ActiveConfigurationBridgeInterface::class])) {
            $bridge = $provider->resolve(ActiveConfigurationBridgeInterface::class);
            break;
        }
    }
    if (!$bridge instanceof ActiveConfigurationBridgeInterface) {
        fwrite(STDERR, "::error::the consumer exposes no active configuration authority\n");
        exit(1);
    }

    $found = null;
    foreach ($bridge->iterate() as $file) {
        if ($file->ref() === 'config.ai_providers') {
            $found = $file;
            break;
        }
    }
    if ($found === null) {
        fwrite(STDERR, "::error::the imported entry is absent from the active store\n");
        exit(1);
    }
    $providers = $found->fields['providers'] ?? null;
    if (!is_array($providers) || ($providers[0]['id'] ?? null) !== 'packaged-proof') {
        fwrite(STDERR, "::error::the active entry does not carry the authored content\n");
        exit(1);
    }
    fwrite(STDOUT, "consumer imported-config read OK\n");

    $marker = 'verified-import-' . bin2hex(random_bytes(4));
    $repository = $kernel->getEntityTypeManager()->getRepository('user');
    $uid = $repository->save(User::make([
        'name' => $marker,
        'mail' => $marker . '@example.test',
        'permissions' => ['access user profiles'],
        'status' => 1,
        'created' => time(),
    ]));
    if ($uid <= 0 || !$repository->find((string) $uid) instanceof User) {
        fwrite(STDERR, "::error::the consumer could not complete a day-one write\n");
        exit(1);
    }

    fwrite(STDOUT, "consumer entity round-trip OK (uid={$uid})\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '::error::consumer runtime FAILED: ' . $e::class . ': ' . $e->getMessage() . "\n");
    for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
        fwrite(STDERR, '  previous: ' . $p::class . ': ' . $p->getMessage() . "\n");
    }
    exit(1);
}
