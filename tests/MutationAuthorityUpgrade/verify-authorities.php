<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Database\DBALDatabase;

[$script, $databasePath, $preservedAuthorityPath] = $argv;

$database = DBALDatabase::createSqlite($databasePath, 'local')->getConnection();
$preserved = json_decode(
    (string) file_get_contents($preservedAuthorityPath),
    true,
    flags: JSON_THROW_ON_ERROR,
);

$preservedTag = $database->fetchOne(<<<'SQL'
    SELECT mutation_tag
    FROM waaseyaa_entity_mutation_authority
    WHERE storage_authority = :storage_authority
      AND tenant_id = :tenant_id
      AND entity_type = :entity_type
      AND entity_id = :entity_id
    SQL, [
    'storage_authority' => $preserved['storage_authority'],
    'tenant_id' => $preserved['tenant_id'],
    'entity_type' => $preserved['entity_type'],
    'entity_id' => $preserved['entity_id'],
]);
if ($preservedTag !== $preserved['mutation_tag']) {
    throw new RuntimeException('An existing mutation authority changed during backfill.');
}

$workflowTag = $database->fetchOne(<<<'SQL'
    SELECT mutation_tag
    FROM waaseyaa_entity_mutation_authority
    WHERE entity_type = 'workflow' AND entity_id = 'editorial'
    SQL);
if (!is_string($workflowTag) || strlen($workflowTag) !== 64) {
    throw new RuntimeException('The editorial workflow authority was not restored.');
}

fwrite(STDOUT, "authority preservation OK\n");
