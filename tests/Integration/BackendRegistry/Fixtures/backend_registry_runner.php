<?php

declare(strict_types=1);

use Waaseyaa\EntityStorage\Backend\FrameworkFieldStorageBackendProvider;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\BackendResolver;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;

/**
 * Runs one phase of the #2160 regression inside a real booted kernel, in a
 * separate process against a real project root — deliberately NOT a
 * hand-constructed EntitySchemaSync, which is the seam the defect fell through.
 */

$projectRoot = $argv[1] ?? throw new RuntimeException('Missing project root.');
$phase = $argv[2] ?? throw new RuntimeException('Missing phase.');

require $projectRoot . '/vendor/autoload.php';

if ($phase === 'db-init') {
    // The real CLI entry point, exactly as a deploy runs it. On alpha.280 this
    // aborts with UnknownBackendException before creating any table.
    $_SERVER['argv'] = [$argv[0], 'db:init'];
    $GLOBALS['argv'] = $_SERVER['argv'];
    exit(new ConsoleKernel($projectRoot)->handle());
}

if ($phase === 'resolve') {
    // Boot the kernel the ordinary way, then ask the registrar — through the
    // same BackendResolver the kernel's own validateQueryDefinitions() uses —
    // whether both reserved ids resolve to a gateway.
    $kernel = new ConsoleKernel($projectRoot);
    $kernel->bootForCli();

    $registrar = new Waaseyaa\EntityStorage\Backend\BackendRegistrarFactory(
        $kernel->getManifest()->providers,
        gatewayAudit: new Waaseyaa\EntityStorage\Backend\DatabaseStrictFieldStorageGatewayAudit(
            $kernel->getDatabase(),
        ),
    )->create();
    $registrar->build();

    $manager = $kernel->getEntityTypeManager();
    $resolver = new BackendResolver($registrar);

    $out = [
        'provider_discovered' => in_array(
            FrameworkFieldStorageBackendProvider::class,
            $kernel->getManifest()->providers,
            true,
        ),
        'has_sql_blob' => $registrar->has(ReservedBackendIds::SQL_BLOB),
        'has_sql_column' => $registrar->has(ReservedBackendIds::SQL_COLUMN),
        'fingerprints' => $registrar->gatewayFingerprints(),
        'resolved' => [],
    ];

    foreach (['registry_column_entity' => 'source_key', 'registry_blob_entity' => 'facet'] as $typeId => $fieldName) {
        $type = $manager->getDefinition($typeId);
        $field = $type->getFieldDefinitions()[$fieldName];
        $out['resolved'][$typeId] = $resolver->resolve($type, $field)->id();
    }

    echo json_encode($out, JSON_THROW_ON_ERROR);
    exit(0);
}

throw new RuntimeException('Unknown phase: ' . $phase);
