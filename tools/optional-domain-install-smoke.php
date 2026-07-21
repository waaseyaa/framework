<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Foundation\Kernel\HttpKernel;

$projectRoot = isset($argv[1]) ? realpath($argv[1]) : false;
$shape = $argv[2] ?? '';

if (!is_string($projectRoot) || !is_dir($projectRoot) || !in_array($shape, ['minimal', 'full'], true)) {
    fwrite(STDERR, "optional-domain-install-smoke: usage: php optional-domain-install-smoke.php <project-root> <minimal|full>\n");
    exit(2);
}

require $projectRoot . '/vendor/autoload.php';

putenv('APP_ENV=local');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';

$expectedPresent = $shape === 'full';
$packages = [
    'waaseyaa/genealogy',
    'waaseyaa/ai-agent',
    'waaseyaa/oidc',
    'waaseyaa/mcp',
    'waaseyaa/wayfinding',
    'waaseyaa/messaging',
    'waaseyaa/engagement',
];

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ($packages as $package) {
    $assert(
        InstalledVersions::isInstalled($package) === $expectedPresent,
        sprintf('%s package %s', $package, $expectedPresent ? 'is not installed' : 'is installed'),
    );
}

$kernel = new HttpKernel($projectRoot);
(new ReflectionMethod($kernel, 'boot'))->invoke($kernel);

$entityIdsByPackage = [
    'waaseyaa/genealogy' => ['genealogy_tree', 'genealogy_person', 'genealogy_event', 'genealogy_family'],
    'waaseyaa/ai-agent' => ['agent_audit_log', 'agent_run'],
    'waaseyaa/oidc' => ['oidc_client'],
    'waaseyaa/wayfinding' => ['wayfinding_trail'],
    'waaseyaa/messaging' => ['message_thread', 'thread_message', 'thread_participant'],
    'waaseyaa/engagement' => ['comment', 'reaction', 'follow'],
];

$entityTypeManager = $kernel->getEntityTypeManager();
$definitionIds = array_keys($entityTypeManager->getDefinitions());
$session = new AdminSurfaceSessionData('smoke-admin', 'Smoke Admin', ['admin'], []);
$catalog = (new GenericAdminSurfaceHost(
    entityTypeManager: $entityTypeManager,
    features: ['mcp' => InstalledVersions::isInstalled('waaseyaa/mcp')],
))->buildCatalog($session)->build();
$catalogIds = array_column($catalog, 'id');

foreach ($entityIdsByPackage as $package => $entityIds) {
    foreach ($entityIds as $entityId) {
        $assert(
            in_array($entityId, $definitionIds, true) === $expectedPresent,
            sprintf('%s entity definition %s', $entityId, $expectedPresent ? 'is absent' : 'is present'),
        );
        $assert(
            in_array($entityId, $catalogIds, true) === $expectedPresent,
            sprintf('%s catalog entry %s', $entityId, $expectedPresent ? 'is absent' : 'is present'),
        );
    }
}

$sessionPayload = (new AdminSurfaceSessionData(
    'smoke-admin',
    'Smoke Admin',
    ['admin'],
    [],
    features: ['mcp' => InstalledVersions::isInstalled('waaseyaa/mcp')],
))->toArray();
$assert(
    ($sessionPayload['features']['mcp'] ?? false) === $expectedPresent,
    sprintf('MCP admin navigation capability is %s', $expectedPresent ? 'absent' : 'present'),
);

$manifestJson = json_encode($kernel->getManifest()->toArray(), JSON_THROW_ON_ERROR);
$manifestNamespaces = [
    'waaseyaa/genealogy' => 'Waaseyaa\\Genealogy\\',
    'waaseyaa/ai-agent' => 'Waaseyaa\\AI\\Agent\\',
    'waaseyaa/oidc' => 'Waaseyaa\\Oidc\\',
    'waaseyaa/mcp' => 'Waaseyaa\\Mcp\\',
    'waaseyaa/wayfinding' => 'Waaseyaa\\Wayfinding\\',
    'waaseyaa/messaging' => 'Waaseyaa\\Messaging\\',
    'waaseyaa/engagement' => 'Waaseyaa\\Engagement\\',
];
foreach ($manifestNamespaces as $package => $namespace) {
    $assert(
        str_contains($manifestJson, str_replace('\\', '\\\\', $namespace)) === $expectedPresent,
        sprintf('%s discovery entries are %s', $package, $expectedPresent ? 'absent' : 'present'),
    );
}

$routes = [
    'waaseyaa/genealogy' => ['GET', '/genealogy', 'genealogy.landing'],
    'waaseyaa/ai-agent' => ['POST', '/api/ai/agent/run', 'api.ai.agent.run.create'],
    'waaseyaa/oidc' => ['GET', '/.well-known/openid-configuration', 'oidc.discovery'],
    'waaseyaa/mcp' => ['POST', '/mcp', 'mcp.endpoint'],
    'waaseyaa/wayfinding' => ['GET', '/.well-known/waaseyaa-anchors.json', 'wayfinding.anchor_catalog'],
    'waaseyaa/messaging' => ['GET', '/api/message_thread', 'api.message_thread.index'],
    'waaseyaa/engagement' => ['GET', '/api/comment', 'api.comment.index'],
];
$matchRoute = new ReflectionMethod($kernel, 'matchRoute');
foreach ($routes as $package => [$method, $path, $expectedRouteName]) {
    $routeResult = $matchRoute->invoke($kernel, $path, $method);
    $actualRouteName = $routeResult instanceof Request ? $routeResult->attributes->get('_route') : null;
    $assert(
        ($actualRouteName === $expectedRouteName) === $expectedPresent,
        sprintf('%s route %s %s is %s', $package, $method, $path, $expectedPresent ? 'absent' : 'present'),
    );
}

if ($failures !== []) {
    fwrite(STDERR, "optional-domain-install-smoke: {$shape} FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

printf(
    "optional-domain-install-smoke: %s PASS (%d packages, %d catalog entries, %d routes)\n",
    $shape,
    count($packages),
    array_sum(array_map('count', $entityIdsByPackage)),
    count($routes),
);
