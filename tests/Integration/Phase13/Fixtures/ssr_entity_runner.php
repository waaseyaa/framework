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
    $nodeRepository = $kernel->getEntityTypeManager()->getRepository('node');
    foreach (WorkflowFixturePack::editorialNodesForSsr() as $fixture) {
        $node = $nodeRepository->create($fixture);
        $nodeRepository->save($node, validate: false);
    }
    $relatedNode = $nodeRepository->create([
        'title' => 'Related Teaching',
        'type' => 'article',
        'status' => 1,
        'workflow_state' => 'published',
    ]);
    $nodeRepository->save($relatedNode, validate: false);

    $pathAliasRepository = $kernel->getEntityTypeManager()->getRepository('path_alias');
    foreach (WorkflowFixturePack::pathAliasesForSsr() as $aliasFixture) {
        $alias = $pathAliasRepository->create($aliasFixture);
        $pathAliasRepository->save($alias, validate: false);
    }

    $relationshipRepository = $kernel->getEntityTypeManager()->getRepository('relationship');
    $relationship = $relationshipRepository->create([
        'relationship_type' => 'references',
        'from_entity_type' => 'node',
        'from_entity_id' => '1',
        'to_entity_type' => 'node',
        'to_entity_id' => '5',
        'directionality' => 'directed',
        'status' => 1,
    ]);
    $relationshipRepository->save($relationship, validate: false);
}

function updateNodeTitle(HttpKernel $kernel, string $title): void
{
    $repository = $kernel->getEntityTypeManager()->getRepository('node');
    $node = $repository->find('1');
    if ($node === null) {
        throw new RuntimeException('Node 1 was not found.');
    }

    $node->set('title', $title);
    $repository->save($node, validate: false);
}

function closeKernelDatabase(HttpKernel $kernel): void
{
    $database = $kernel->getDatabase();
    if ($database instanceof DBALDatabase) {
        $database->getConnection()->close();
    }
}
