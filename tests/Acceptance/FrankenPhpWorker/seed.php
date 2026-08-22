<?php

declare(strict_types=1);

/**
 * Seeds synthetic users and nodes for FrankenPHP worker-runtime acceptance.
 * Invoked only by scripts/acceptance-frankenphp-worker.sh.
 */

use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeType;
use Waaseyaa\User\User;

$projectRoot = $argv[1] ?? '';
$outFile = $argv[2] ?? '';
if ($projectRoot === '' || $outFile === '') {
    fwrite(STDERR, "usage: seed.php <project-root> <ids.json>\n");
    exit(2);
}

require $projectRoot . '/vendor/autoload.php';

$kernel = new HttpKernel($projectRoot);
$kernel->bootForSchemaSync();
$entityTypeManager = $kernel->getEntityTypeManager();

$userRepository = $entityTypeManager->getRepository('user');
$alicePassword = 'alice-acceptance-' . bin2hex(random_bytes(8));
$bobPassword = 'bob-acceptance-' . bin2hex(random_bytes(8));

$alice = new User([
    'name' => 'acceptance-alice',
    'mail' => 'alice@acceptance.test',
    'pass' => password_hash($alicePassword, PASSWORD_DEFAULT),
    'status' => true,
    'roles' => ['admin'],
    'permissions' => [
        'administer nodes',
        'access content',
        'view own unpublished content',
        'access user profiles',
    ],
]);
$alice->enforceIsNew();
$userRepository->save($alice);

$bob = new User([
    'name' => 'acceptance-bob',
    'mail' => 'bob@acceptance.test',
    'pass' => password_hash($bobPassword, PASSWORD_DEFAULT),
    'status' => true,
    'roles' => ['authenticated'],
    'permissions' => [
        'access content',
    ],
]);
$bob->enforceIsNew();
$userRepository->save($bob);

$nodeTypeRepository = $entityTypeManager->getRepository('node_type');
$pageType = new NodeType(['type' => 'page', 'name' => 'Page']);
$pageType->enforceIsNew();
$nodeTypeRepository->save($pageType);

$nodeRepository = $entityTypeManager->getRepository('node');
$alpha = new Node([
    'title' => 'Acceptance Alpha Twig Unique Token',
    'type' => 'page',
    'slug' => 'acceptance-alpha-twig',
    'status' => true,
    'uid' => (int) $alice->id(),
]);
$alpha->enforceIsNew();
$nodeRepository->save($alpha);

$beta = new Node([
    'title' => 'Acceptance Beta Twig Unique Token',
    'type' => 'page',
    'slug' => 'acceptance-beta-twig',
    'status' => true,
    'uid' => (int) $bob->id(),
]);
$beta->enforceIsNew();
$nodeRepository->save($beta);

$hidden = new Node([
    'title' => 'Acceptance Concealed Draft Token',
    'type' => 'page',
    'slug' => 'acceptance-concealed-draft',
    'status' => false,
    'uid' => (int) $alice->id(),
]);
$hidden->enforceIsNew();
$nodeRepository->save($hidden);

$payload = [
    'alice' => [
        'id' => (string) $alice->id(),
        'name' => 'acceptance-alice',
        'password' => $alicePassword,
    ],
    'bob' => [
        'id' => (string) $bob->id(),
        'name' => 'acceptance-bob',
        'password' => $bobPassword,
    ],
    'nodes' => [
        'alpha' => (string) $alpha->id(),
        'beta' => (string) $beta->id(),
        'hidden' => (string) $hidden->id(),
    ],
];

file_put_contents($outFile, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
fwrite(STDOUT, "seeded acceptance identities\n");
