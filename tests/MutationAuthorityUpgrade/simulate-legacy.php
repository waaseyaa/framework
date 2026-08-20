<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Database\DBALDatabase;

[$script, $databasePath, $preservedAuthorityPath] = $argv;

$database = DBALDatabase::createSqlite($databasePath, 'local')->getConnection();

$workflowAuthority = $database->fetchAssociative(<<<'SQL'
    SELECT storage_authority, tenant_id, entity_type, entity_id, mutation_tag
    FROM waaseyaa_entity_mutation_authority
    WHERE entity_type = 'workflow' AND entity_id = 'editorial'
    SQL);
if (!is_array($workflowAuthority)) {
    throw new RuntimeException('The prepared consumer has no editorial workflow authority to remove.');
}

$preservedAuthority = $database->fetchAssociative(<<<'SQL'
    SELECT storage_authority, tenant_id, entity_type, entity_id, mutation_tag
    FROM waaseyaa_entity_mutation_authority
    WHERE NOT (entity_type = 'workflow' AND entity_id = 'editorial')
    ORDER BY entity_type, entity_id
    LIMIT 1
    SQL);
if (!is_array($preservedAuthority)) {
    throw new RuntimeException('The prepared consumer has no unrelated authority to preserve.');
}

file_put_contents(
    $preservedAuthorityPath,
    json_encode($preservedAuthority, JSON_THROW_ON_ERROR),
);

$deleted = $database->executeStatement(<<<'SQL'
    DELETE FROM waaseyaa_entity_mutation_authority
    WHERE storage_authority = :storage_authority
      AND tenant_id = :tenant_id
      AND entity_type = :entity_type
      AND entity_id = :entity_id
    SQL, [
    'storage_authority' => $workflowAuthority['storage_authority'],
    'tenant_id' => $workflowAuthority['tenant_id'],
    'entity_type' => $workflowAuthority['entity_type'],
    'entity_id' => $workflowAuthority['entity_id'],
]);

if ($deleted !== 1) {
    throw new RuntimeException('The legacy simulation did not remove exactly one authority row.');
}

fwrite(STDOUT, "legacy authority gap prepared\n");
