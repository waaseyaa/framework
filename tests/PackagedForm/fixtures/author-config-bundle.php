<?php

declare(strict_types=1);

/**
 * Author one valid strict-v1 configuration entry on the authoring host (#2430).
 *
 * Runs inside the authoring consumer so the file is produced against the schema
 * registry the running site actually has frozen, rather than a hand-written YAML
 * that happens to look right. It writes into config/sync and nothing else — no
 * active store is touched and no generation is activated.
 *
 * The authored content is deliberately credential-free (a `null` provider), so
 * nothing in this proof's bundle could be mistaken for secret material.
 */

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Config\Schema\Ai\ProvidersConfig;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;

$kernel = new ConsoleKernel(__DIR__);
$kernel->bootForCli();

$registry = null;
foreach ($kernel->getProviders() as $provider) {
    if (isset($provider->getBindings()[ConfigSchemaRegistry::class])) {
        $registry = $provider->resolve(ConfigSchemaRegistry::class);
        break;
    }
}
if (!$registry instanceof ConfigSchemaRegistry) {
    fwrite(STDERR, "The authoring host has no configuration schema registry.\n");
    exit(1);
}

$registration = $registry->get(ProvidersConfig::CONFIG_NAME, ProvidersConfig::SCHEMA_VERSION);
if ($registration === null) {
    fwrite(STDERR, "The authoring host has not registered the providers schema.\n");
    exit(1);
}

$file = ConfigSyncFile::writable(
    entityType: 'config',
    entityId: 'ai_providers',
    uuid: ConfigSyncFile::deterministicUuid('config', 'ai_providers'),
    dependencies: [],
    langcode: 'en',
    fields: ['providers' => [[
        'id' => 'packaged-proof',
        'type' => 'null',
        'model_default' => 'none',
        'timeout_ms' => 1000,
        'rate_limit_per_min' => 0,
    ]]],
    schemaId: $registration->schemaId,
    schemaVersion: $registration->schemaVersion,
    schemaHash: $registration->canonicalSchemaHash,
    ownerPackage: $registration->ownerPackage,
    ownerConfigContractVersion: $registration->ownerConfigContractVersion,
);

$syncPath = __DIR__ . '/config/sync';
file_put_contents($syncPath . '/' . $file->filename(), new ConfigSyncSerializer()->toYaml($file));

fwrite(STDOUT, "authored {$file->ref()}\n");
exit(0);
