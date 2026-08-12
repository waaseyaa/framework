<?php

declare(strict_types=1);

use Waaseyaa\Config\Activation\ConfigurationActivationAuthorizerInterface;
use Waaseyaa\Config\Activation\ConfigurationActivationConflictException;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationRollbackRequest;
use Waaseyaa\Config\Activation\ConfigurationRollbackValidatorInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Sync\ConfigSyncFile;
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
] as $migrationFile) {
    $migration = require __DIR__ . '/vendor/waaseyaa/entity-storage/migrations/' . $migrationFile;
    $migration->up(new SchemaBuilder($database->getConnection()));
}
$context = new ConfigurationAuthorityContext(
    authorityId: str_repeat('a', 64),
    databaseIdentity: $database->databaseIdentity(),
    syncPath: __DIR__ . '/config-sync',
    selectorProvenance: ['packaged-proof'],
    packageManifestHash: str_repeat('b', 64),
    schemaVersion: 'packaged-proof-v1',
);
$authorizer = new class implements ConfigurationActivationAuthorizerInterface {
    public function authorize(ConfigurationActivationRequest $request, bool $deletes): void {}
};
$validator = new class implements ConfigurationRollbackValidatorInterface {
    public function validate(ConfigurationRollbackRequest $request, array $targetFiles): void {}
};
$activator = new DatabaseConfigurationActivator($database, $context, $authorizer, $validator);
$file = static fn(string $name): ConfigSyncFile => new ConfigSyncFile(
    'system',
    'site',
    ConfigSyncFile::deterministicUuid('system', 'site'),
    [],
    'en',
    ['name' => $name],
);
$firstRequest = new ConfigurationActivationRequest('packaged-first', null, [$file('A')]);
$first = $activator->activate($firstRequest);
$retry = $activator->activate($firstRequest);
if ($first->token != $retry->token || $first->evidenceHash() !== $retry->evidenceHash()) {
    throw new RuntimeException('Packaged activation retry was not idempotent and evidence-stable.');
}
$second = $activator->activate(new ConfigurationActivationRequest('packaged-second', $first->token, [$file('B')]));
try {
    $activator->activate(new ConfigurationActivationRequest('packaged-stale', $first->token, [$file('C')]));
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
