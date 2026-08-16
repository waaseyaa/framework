<?php

declare(strict_types=1);

use Waaseyaa\Config\Activation\ConfigurationActivationAuthorizerInterface;
use Waaseyaa\Config\Activation\ConfigurationActivationConflictException;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationRollbackRequest;
use Waaseyaa\Config\Activation\ConfigurationRollbackValidatorInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeVerifier;
use Waaseyaa\Config\Manifest\ConfigSyncBundleManifest;
use Waaseyaa\Config\Manifest\UnsignedConfigPolicy;
use Waaseyaa\Config\Manifest\VerifiedConfigBundle;
use Waaseyaa\Config\Schema\ConfigContentHasher;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigPackageContract;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Config\Sync\ValidatedConfigSyncEntry;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Config\DatabaseConfigurationActivator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Runtime\RuntimeEpochInterface;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\Security\SignedQueuePayload;
use Waaseyaa\Queue\Transport\TransportInterface;
use Waaseyaa\Queue\Worker\Worker;
use Waaseyaa\Queue\Worker\WorkerOptions;

require __DIR__ . '/vendor/autoload.php';

$database = DBALDatabase::createSqlite(__DIR__ . '/activation.sqlite', 'testing');
foreach ([
    '2026_08_12_000002_configuration_authority.php',
    '2026_08_12_000003_configuration_activation.php',
    '2026_08_15_000004_configuration_manifest_replay.php',
] as $migrationFile) {
    $migration = require __DIR__ . '/vendor/waaseyaa/entity-storage/migrations/' . $migrationFile;
    $migration->up(new SchemaBuilder($database->getConnection()));
}
$context = new ConfigurationAuthorityContext(
    authorityId: str_repeat('a', 64),
    databaseIdentity: $database->databaseIdentity(),
    syncPath: __DIR__ . '/config-sync',
    selectorProvenance: ['packaged-proof'],
);
$authorizer = new class implements ConfigurationActivationAuthorizerInterface {
    public function authorize(ConfigurationActivationRequest $request, bool $deletes): void {}
};
$validator = new class implements ConfigurationRollbackValidatorInterface {
    public function validate(ConfigurationRollbackRequest $request, array $targetFiles): void {}
};
$activator = new DatabaseConfigurationActivator($database, $context, $authorizer, $validator);
$bundle = static function (string $name, int $sequence): VerifiedConfigBundle {
    $registry = new ConfigSchemaRegistry();
    $registration = $registry->register('waaseyaa.test.config', 1, 'waaseyaa/config', 1, [
        'dialect' => ConfigSchemaRegistry::DIALECT_V1,
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'required' => ['name'],
    ]);
    $registry->freeze();
    $file = ConfigSyncFile::writable(
        'system',
        'site',
        ConfigSyncFile::deterministicUuid('system', 'site'),
        [],
        'en',
        ['name' => $name],
        schemaId: $registration->schemaId,
        schemaVersion: $registration->schemaVersion,
        schemaHash: $registration->canonicalSchemaHash,
        ownerPackage: $registration->ownerPackage,
        ownerConfigContractVersion: $registration->ownerConfigContractVersion,
    );
    $bytes = new ConfigSyncSerializer()->toYaml($file);
    $result = new ConfigSyncBundleValidationResult([
        new ValidatedConfigSyncEntry($file, $bytes, new ConfigContentHasher()->hash($file, $bytes, $registry)),
    ], []);
    $manifest = ConfigSyncBundleManifest::fromValidatedBundle(
        $result,
        $registry,
        'test:packaged-activation',
        $sequence,
        ['producer' => 'exact-head-packaged-proof'],
        ['waaseyaa/config' => 1],
    );
    $verification = new ConfigManifestEnvelopeVerifier()->verifyUnsigned(
        $manifest,
        UnsignedConfigPolicy::sealedLocal('authority:packaged-proof', 'sha256:' . str_repeat('a', 64)),
    );

    return VerifiedConfigBundle::bind(
        $result,
        $registry,
        $verification,
        new ConfigPackageCompatibility([
            new ConfigPackageContract('waaseyaa/config', 1, 'packaged-proof.Provider', [1]),
        ]),
    );
};
$firstRequest = ConfigurationActivationRequest::activateVerified('packaged-first', null, $bundle('A', 1));
$first = $activator->activate($firstRequest);
$retry = $activator->activate($firstRequest);
if ($first->token != $retry->token || $first->evidenceHash() !== $retry->evidenceHash()) {
    throw new RuntimeException('Packaged activation retry was not idempotent and evidence-stable.');
}
$second = $activator->activate(ConfigurationActivationRequest::activateVerified('packaged-second', $first->token, $bundle('B', 2)));
try {
    $activator->activate(ConfigurationActivationRequest::activateVerified('packaged-stale', $first->token, $bundle('C', 3)));
    throw new RuntimeException('Packaged activation accepted a stale token.');
} catch (ConfigurationActivationConflictException) {
}
$rolledBack = $activator->rollback(new ConfigurationRollbackRequest(
    'packaged-rollback',
    $second->token,
    $first->token,
));
if ($rolledBack->token->generationId !== $first->token->generationId
    || $rolledBack->token->activationSequence <= $second->token->activationSequence) {
    throw new RuntimeException('Packaged rollback did not reactivate retained content with a new sequence.');
}

$transport = new class implements TransportInterface {
    public int $pops = 0;
    public function push(string $queue, string $payload, int $delay = 0): void {}
    public function pop(string $queue): ?array
    {
        ++$this->pops;
        return null;
    }
    public function ack(int|string $jobId): void {}
    public function reject(int|string $jobId): void {}
    public function release(int|string $jobId, int $delay = 0): void {}
    public function defer(int|string $jobId, int $delay = 0): void {}
    public function size(string $queue): int
    {
        return 0;
    }
    public function purge(string $queue): void {}
    public function listJobs(int $limit, int $offset = 0, ?string $status = null): array
    {
        return ['data' => [], 'total' => 0];
    }
};
$failed = new class implements FailedJobRepositoryInterface {
    public function record(string $queue, string $payload, Throwable $e): string
    {
        return 'unused';
    }
    public function all(): array
    {
        return [];
    }
    public function find(string $id): ?array
    {
        return null;
    }
    public function forget(string $id): void {}
    public function flush(): void {}
    public function retry(string $id): ?array
    {
        return null;
    }
    public function claimForRetry(string $id): bool
    {
        return false;
    }
    public function releaseRetryClaim(string $id): void {}
};
$changedEpoch = new class implements RuntimeEpochInterface {
    public function fingerprint(): string
    {
        return 'configuration:v1:changed';
    }
    public function hasChanged(): bool
    {
        return true;
    }
};
$worker = new Worker($transport, $failed, [], new SignedQueuePayload(str_repeat('k', 32)), runtimeEpoch: $changedEpoch);
if ($worker->run('default', new WorkerOptions(maxJobs: 1)) !== 0 || $transport->pops !== 0) {
    throw new RuntimeException('Packaged worker claimed work after its runtime epoch changed.');
}

echo "configuration activation packaged proof passed\n";
