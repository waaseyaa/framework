<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Migration\ContentModel\ContentModelRegistrar;
use Waaseyaa\Migration\ServiceProvider as MigrationServiceProvider;
use Waaseyaa\Tests\Integration\FreshInstall\Fixtures\CutoverContentModelProvider;

$projectRoot = $argv[1] ?? throw new RuntimeException('Missing project root.');
$phase = $argv[2] ?? throw new RuntimeException('Missing phase.');

require $projectRoot . '/vendor/autoload.php';

if ($phase === 'db-init' || $phase === 'schema-check') {
    $_SERVER['argv'] = [$argv[0], $phase === 'db-init' ? 'db:init' : 'schema:check'];
    $GLOBALS['argv'] = $_SERVER['argv'];
    exit(new ConsoleKernel($projectRoot)->handle());
}

if ($phase === 'import') {
    $kernel = new ConsoleKernel($projectRoot);
    $kernel->bootForCli();

    $migrationProvider = null;
    $modelProvider = null;
    foreach ($kernel->getProviders() as $provider) {
        $migrationProvider ??= $provider instanceof MigrationServiceProvider ? $provider : null;
        $modelProvider ??= $provider instanceof CutoverContentModelProvider ? $provider : null;
    }
    if (!$migrationProvider instanceof MigrationServiceProvider || !$modelProvider instanceof CutoverContentModelProvider) {
        throw new RuntimeException('Fresh-install smoke providers were not discovered.');
    }

    $registrar = $migrationProvider->resolve(ContentModelRegistrar::class);
    assert($registrar instanceof ContentModelRegistrar);
    $registrar->register($modelProvider->deriveContentModel());

    $nodes = $kernel->getEntityTypeManager()->getRepository('node');
    $source = $nodes->create([
        'title' => 'Fresh-install source',
        'type' => 'cutover_page',
        'body' => '<p>Fresh-install bundle content.</p>',
        'status' => 1,
        'workflow_state' => 'published',
    ]);
    $nodes->save($source, validate: false);
    $target = $nodes->create([
        'title' => 'Fresh-install target',
        'type' => 'cutover_page',
        'body' => '<p>Related content.</p>',
        'status' => 1,
        'workflow_state' => 'published',
    ]);
    $nodes->save($target, validate: false);

    $relationships = $kernel->getEntityTypeManager()->getRepository('relationship');
    $relationship = $relationships->create([
        'relationship_type' => 'references',
        'from_entity_type' => 'node',
        'from_entity_id' => (string) $source->id(),
        'to_entity_type' => 'node',
        'to_entity_id' => (string) $target->id(),
        'directionality' => 'directed',
        'status' => 1,
    ]);
    $relationships->save($relationship, validate: false);

    echo json_encode([
        'source_id' => (string) $source->id(),
        'target_id' => (string) $target->id(),
        'relationship_id' => (string) $relationship->id(),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($phase === 'http') {
    $sourceId = $argv[3] ?? throw new RuntimeException('Missing source entity id.');
    $_GET = [];
    $_POST = [];
    $_COOKIE = [];
    $_FILES = [];
    $_REQUEST = [];
    $_SERVER = [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/node/' . rawurlencode($sourceId),
        'QUERY_STRING' => '',
        'HTTP_HOST' => 'localhost',
        'SERVER_NAME' => 'localhost',
        'SERVER_PORT' => '80',
        'HTTPS' => 'off',
    ];

    $response = new HttpKernel($projectRoot)->handle();
    echo json_encode([
        'status' => $response->getStatusCode(),
        'body' => (string) $response->getContent(),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

throw new RuntimeException('Unknown phase: ' . $phase);
