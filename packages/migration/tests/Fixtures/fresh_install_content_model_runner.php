<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Migration\ContentModel\ContentModelRegistrar;
use Waaseyaa\Migration\ServiceProvider as MigrationServiceProvider;
use Waaseyaa\Migration\Tests\Fixtures\FreshInstallContentModelProvider;
use Waaseyaa\Migration\Tests\Fixtures\FreshInstallPage;

$projectRoot = $argv[1] ?? throw new RuntimeException('Missing project root.');
$phase = $argv[2] ?? throw new RuntimeException('Missing phase.');

require $projectRoot . '/vendor/autoload.php';

if ($phase === 'db-init') {
    $_SERVER['argv'] = [$argv[0], 'db:init'];
    $GLOBALS['argv'] = $_SERVER['argv'];
    exit((new ConsoleKernel($projectRoot))->handle());
}

if ($phase === 'import') {
    $kernel = new ConsoleKernel($projectRoot);
    $kernel->bootForCli();

    $migrationProvider = null;
    $modelProvider = null;
    foreach ($kernel->getProviders() as $provider) {
        $migrationProvider ??= $provider instanceof MigrationServiceProvider ? $provider : null;
        $modelProvider ??= $provider instanceof FreshInstallContentModelProvider ? $provider : null;
    }
    if (!$migrationProvider instanceof MigrationServiceProvider || !$modelProvider instanceof FreshInstallContentModelProvider) {
        throw new RuntimeException('Fresh-install providers were not discovered.');
    }

    $registrar = $migrationProvider->resolve(ContentModelRegistrar::class);
    assert($registrar instanceof ContentModelRegistrar);
    $registrar->register($modelProvider->deriveContentModel());

    $entity = new FreshInstallPage([
        'title' => 'Imported home',
        'page_type' => FreshInstallContentModelProvider::BUNDLE,
        'body' => '<p>Persisted bundle-field content.</p>',
    ]);
    $entity->enforceIsNew();
    $kernel->getEntityTypeManager()->getRepository(FreshInstallContentModelProvider::ENTITY_TYPE)->save($entity);

    echo (string) $entity->id();
    exit(0);
}

if ($phase === 'http-read') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['HTTP_HOST'] = 'localhost';

    $kernel = new HttpKernel($projectRoot);
    $kernel->handle(); // Real HTTP-kernel boot; the route result is immaterial to this storage read.
    $entities = $kernel->getEntityTypeManager()
        ->getRepository(FreshInstallContentModelProvider::ENTITY_TYPE)
        ->findBy(['title' => 'Imported home']);

    echo (string) ($entities[0]?->get('body') ?? '');
    exit(0);
}

throw new RuntimeException('Unknown phase: ' . $phase);
