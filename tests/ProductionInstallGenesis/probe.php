<?php

declare(strict_types=1);

/**
 * Production install genesis probe (#3064).
 *
 * Modes:
 * - refuse: ordinary HttpKernel boot must fail closed before genesis.
 * - boot: ordinary HttpKernel boot must succeed and expose the active generation.
 */

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Foundation\Kernel\HttpKernel;

$mode = $argv[1] ?? '';
if (!in_array($mode, ['refuse', 'boot'], true)) {
    fwrite(STDERR, "usage: php probe.php refuse|boot\n");
    exit(2);
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $kernel = new HttpKernel(__DIR__);
    new ReflectionMethod($kernel, 'boot')->invoke($kernel);
} catch (Throwable $e) {
    if ($mode === 'refuse') {
        $message = $e->getMessage();
        if (!str_contains($message, 'Active configuration generation is unavailable')
            && !$e instanceof ConfigurationAuthorityUnavailableException
        ) {
            fwrite(STDERR, '::error::expected generation refusal, got ' . $e::class . ': ' . $message . "\n");
            exit(1);
        }
        fwrite(STDOUT, "production-install ordinary boot refused before genesis\n");
        fwrite(STDERR, $message . "\n");
        exit(1);
    }

    fwrite(STDERR, '::error::production boot failed: ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}

if ($mode === 'refuse') {
    fwrite(STDERR, "::error::ordinary production boot succeeded before genesis\n");
    exit(0);
}

$context = null;
foreach ($kernel->getProviders() as $provider) {
    if (!isset($provider->getBindings()[ConfigurationAuthorityContext::class])) {
        continue;
    }
    try {
        $resolved = $provider->resolve(ConfigurationAuthorityContext::class);
    } catch (Throwable) {
        continue;
    }
    if ($resolved instanceof ConfigurationAuthorityContext) {
        $context = $resolved;
        break;
    }
}

if (!$context instanceof ConfigurationAuthorityContext) {
    fwrite(STDERR, "::error::active configuration authority context was not composed\n");
    exit(1);
}

$generationId = $context->requireActiveGenerationId();
if (preg_match('/^[a-f0-9]{64}$/D', $generationId) !== 1) {
    fwrite(STDERR, "::error::active generation id is malformed\n");
    exit(1);
}

fwrite(STDOUT, "production-install ordinary boot OK\n");
fwrite(STDOUT, "production-install active generation OK ({$generationId})\n");
exit(0);
