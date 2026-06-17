<?php

declare(strict_types=1);

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Tests\Support\WorkflowFixturePack;

if ($argc < 4) {
    fwrite(STDERR, "Usage: php ssr_entity_runner.php <repo_root> <project_root> <action> [value]\n");
    exit(1);
}

$repoRoot = (string) $argv[1];
$projectRoot = (string) $argv[2];
$action = (string) $argv[3];
$value = isset($argv[4]) ? (string) $argv[4] : '';

require $repoRoot . '/vendor/autoload.php';

try {
    $kernel = new HttpKernel($projectRoot);
    $boot = new ReflectionMethod(AbstractKernel::class, 'boot');
    $boot->invoke($kernel);

    match ($action) {
        'seed' => seedSsrFixtures($kernel),
        'update-node-title' => updateNodeTitle($kernel, $value),
        default => throw new InvalidArgumentException(sprintf('Unknown SSR fixture action: %s', $action)),
    };

    closeKernelDatabase($kernel);
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
    exit(1);
}

function seedSsrFixtures(HttpKernel $kernel): void
{
    $nodeStorage = $kernel->getEntityTypeManager()->getStorage('node');
    foreach (WorkflowFixturePack::editorialNodesForSsr() as $fixture) {
        $node = $nodeStorage->create($fixture);
        $nodeStorage->save($node);
    }

    $pathAliasStorage = $kernel->getEntityTypeManager()->getStorage('path_alias');
    foreach (WorkflowFixturePack::pathAliasesForSsr() as $aliasFixture) {
        $alias = $pathAliasStorage->create($aliasFixture);
        $pathAliasStorage->save($alias);
    }
}

function updateNodeTitle(HttpKernel $kernel, string $title): void
{
    $storage = $kernel->getEntityTypeManager()->getStorage('node');
    $node = $storage->load(1);
    if ($node === null) {
        throw new RuntimeException('Node 1 was not found.');
    }

    $node->set('title', $title);
    $storage->save($node);
}

function closeKernelDatabase(HttpKernel $kernel): void
{
    $database = $kernel->getDatabase();
    if ($database instanceof DBALDatabase) {
        $database->getConnection()->close();
    }
}
