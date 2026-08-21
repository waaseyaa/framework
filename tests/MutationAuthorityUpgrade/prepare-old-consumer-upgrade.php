<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Concurrency\EntityMutationAuthority;

[$script, $databasePath, $preservedAuthorityPath] = $argv;

$database = DBALDatabase::createSqlite($databasePath, 'local');
$connection = $database->getConnection();
$workflowAuthority = $connection->fetchOne(<<<'SQL'
    SELECT mutation_tag
    FROM waaseyaa_entity_mutation_authority
    WHERE entity_type = 'workflow' AND entity_id = 'editorial'
    SQL);
if ($workflowAuthority !== false) {
    throw new RuntimeException('The released pre-DB-03 workflow unexpectedly already has mutation authority.');
}

$token = new EntityMutationAuthority($database, 'primary')
    ->create('_global', 'upgrade-proof', 'preserved');
file_put_contents($preservedAuthorityPath, json_encode([
    'storage_authority' => $token->storageAuthority,
    'tenant_id' => $token->tenantId,
    'entity_type' => $token->entityTypeId,
    'entity_id' => $token->entityId,
    'mutation_tag' => $token->tagHex(),
], JSON_THROW_ON_ERROR));

fwrite(STDOUT, "released legacy database prepared\n");
