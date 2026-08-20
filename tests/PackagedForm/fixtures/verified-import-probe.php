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

use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\User;

try {
    $kernel = new HttpKernel(__DIR__);
    new ReflectionMethod($kernel, 'boot')->invoke($kernel);
    fwrite(STDOUT, "consumer ordinary boot OK\n");

    $factory = null;
    foreach ($kernel->getProviders() as $provider) {
        if (isset($provider->getBindings()[ConfigFactoryInterface::class])) {
            $factory = $provider->resolve(ConfigFactoryInterface::class);
            break;
        }
    }
    if (!$factory instanceof ConfigFactoryInterface) {
        fwrite(STDERR, "::error::the consumer exposes no configuration read API\n");
        exit(1);
    }

    // The imported entry must be readable through the ordinary runtime path,
    // from the active store — never by reading the sync directory.
    $providers = $factory->get('config.ai.providers')->get('providers');
    if (!is_array($providers) || ($providers[0]['id'] ?? null) !== 'packaged-proof') {
        fwrite(STDERR, "::error::imported configuration is not readable from the active store\n");
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
