<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Api\ApiDiscoveryController;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\User;

$projectRoot = isset($argv[1]) ? realpath($argv[1]) : false;
$shape = $argv[2] ?? '';

if (!is_string($projectRoot) || !is_dir($projectRoot) || !in_array($shape, ['minimal', 'full'], true)) {
    fwrite(STDERR, "optional-domain-install-smoke: usage: php optional-domain-install-smoke.php <project-root> <minimal|full>\n");
    exit(2);
}

require $projectRoot . '/vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('APP_URL=https://catalog-smoke.example');
$_ENV['APP_URL'] = $_SERVER['APP_URL'] = 'https://catalog-smoke.example';

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
$corePackages = [
    'waaseyaa/entity',
    'waaseyaa/entity-storage',
    'waaseyaa/node',
    'waaseyaa/taxonomy',
    'waaseyaa/media',
    'waaseyaa/menu',
    'waaseyaa/user',
    'waaseyaa/workflows',
    'waaseyaa/config',
    'waaseyaa/groups',
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
foreach ($corePackages as $package) {
    $assert(InstalledVersions::isInstalled($package), sprintf('core package %s is not installed', $package));
}

$kernel = new HttpKernel($projectRoot);
(new ReflectionMethod($kernel, 'boot'))->invoke($kernel);
$matchRoute = new ReflectionMethod($kernel, 'matchRoute');

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
$adminAccount = User::make([
    'uid' => 1,
    'name' => 'smoke-admin',
    'roles' => ['administrator'],
    'status' => true,
]);
$principalFactory = $kernel->getHttpServiceResolver()->resolve(AccountPrincipalFactoryInterface::class);
$assert(
    $principalFactory instanceof AccountPrincipalFactoryInterface,
    'account principal factory is not available',
);
$adminPrincipal = $principalFactory instanceof AccountPrincipalFactoryInterface
    ? $principalFactory->fromAccount($adminAccount)
    : null;

$invokeSurfaceRoute = static function (string $path, string $expectedRouteName) use ($kernel, $matchRoute, $adminAccount, $adminPrincipal, $assert): array {
    $matched = $matchRoute->invoke($kernel, $path, 'GET');
    $assert($matched instanceof Request, sprintf('admin surface route %s did not match', $path));
    if (!$matched instanceof Request) {
        return [];
    }
    $assert(
        $matched->attributes->get('_route') === $expectedRouteName,
        sprintf('admin surface path %s matched %s instead of %s', $path, $matched->attributes->get('_route'), $expectedRouteName),
    );

    $controller = $matched->attributes->get('_controller');
    $assert(is_callable($controller), sprintf('admin surface route %s has no callable controller', $path));
    if (!is_callable($controller)) {
        return [];
    }

    $request = Request::create($path);
    $request->attributes->set('_account', $adminAccount);
    if ($adminPrincipal !== null) {
        $request->attributes->set('_authorization_principal', $adminPrincipal);
    }
    $result = $controller($request);
    $assert($result instanceof Response, sprintf('admin surface route %s returned no response', $path));
    if (!$result instanceof Response) {
        return [];
    }
    $assert($result->getStatusCode() === 200, sprintf('admin surface route %s returned HTTP %d', $path, $result->getStatusCode()));
    $assert($result->headers->get('Content-Type') === 'application/json', sprintf('admin surface route %s returned the wrong media type', $path));

    try {
        $payload = json_decode((string) $result->getContent(), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $payload = null;
    }
    $assert(is_array($payload), sprintf('admin surface route %s returned no JSON envelope', $path));

    return is_array($payload) ? $payload : [];
};

$sessionPayload = $invokeSurfaceRoute('/admin/_surface/session', 'admin_surface.session');
$catalogPayload = $invokeSurfaceRoute('/admin/_surface/catalog', 'admin_surface.catalog');
$catalogIds = array_column($catalogPayload['data']['entities'] ?? [], 'id');

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

$assert(
    ($sessionPayload['data']['features']['mcp'] ?? false) === $expectedPresent,
    sprintf('MCP admin navigation capability is %s', $expectedPresent ? 'absent' : 'present'),
);

$apiDiscoveryRoute = $matchRoute->invoke($kernel, '/api', 'GET');
$assert($apiDiscoveryRoute instanceof Request, 'API discovery route /api did not match');
if ($apiDiscoveryRoute instanceof Request) {
    $assert(
        $apiDiscoveryRoute->attributes->get('_route') === 'api.discovery',
        sprintf('API discovery path /api matched %s instead of api.discovery', $apiDiscoveryRoute->attributes->get('_route')),
    );
}
$apiDiscovery = (new ApiDiscoveryController($entityTypeManager, account: $adminAccount))->discover();
$apiDiscoveryIds = array_keys($apiDiscovery['links']);
$apiEntityIds = array_merge(
    $entityIdsByPackage['waaseyaa/genealogy'],
    $entityIdsByPackage['waaseyaa/oidc'],
    $entityIdsByPackage['waaseyaa/wayfinding'],
    $entityIdsByPackage['waaseyaa/messaging'],
    $entityIdsByPackage['waaseyaa/engagement'],
);
foreach ($apiEntityIds as $entityId) {
    $assert(
        in_array($entityId, $apiDiscoveryIds, true) === $expectedPresent,
        sprintf('%s API discovery link is %s', $entityId, $expectedPresent ? 'absent' : 'present'),
    );
}

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
foreach ($routes as $package => [$method, $path, $expectedRouteName]) {
    $routeResult = $matchRoute->invoke($kernel, $path, $method);
    $actualRouteName = $routeResult instanceof Request ? $routeResult->attributes->get('_route') : null;
    $assert(
        ($actualRouteName === $expectedRouteName) === $expectedPresent,
        sprintf('%s route %s %s is %s', $package, $method, $path, $expectedPresent ? 'absent' : 'present'),
    );
}

$catalogRoute = $matchRoute->invoke($kernel, '/.well-known/api-catalog', 'GET');
$catalogRouteName = $catalogRoute instanceof Request ? $catalogRoute->attributes->get('_route') : null;
$assert(
    ($catalogRouteName === 'api.catalog') === $expectedPresent,
    sprintf('RFC 9727 API catalog route is %s', $expectedPresent ? 'absent' : 'present'),
);

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
