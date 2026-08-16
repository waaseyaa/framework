<?php

declare(strict_types=1);

/**
 * Packaged-form smoke test (#1315 Criterion B).
 *
 * Boots the framework against a freshly `composer create-project waaseyaa/waaseyaa`
 * install and exercises the User entity end-to-end: save -> reload -> assert.
 *
 * Catches the alpha.148 -> alpha.151 class of failure where the framework's
 * own test harness boots differently than a Packagist-installed consumer app.
 *
 * Usage: php smoke.php /path/to/skeleton-install
 *   or:  php smoke.php   (defaults to getcwd)
 *
 * Exit codes: 0 success; non-zero failure.
 */

use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\User;

$projectRoot = $argv[1] ?? getcwd();
$projectRoot = (string) realpath($projectRoot);

if ($projectRoot === '' || !is_dir($projectRoot)) {
    fwrite(STDERR, "skeleton-smoke: invalid project root\n");
    exit(2);
}

$autoload = $projectRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "skeleton-smoke: vendor/autoload.php not found at {$projectRoot}\n");
    exit(2);
}
require $autoload;

if (is_file($projectRoot . '/.env')) {
    (new \Symfony\Component\Dotenv\Dotenv())->loadEnv($projectRoot . '/.env', 'APP_ENV', 'production');
}

echo "skeleton-smoke: booting HttpKernel against {$projectRoot}\n";

// A fresh consumer must cross the explicit schema-transition boundary before
// ordinary runtime boot. Runtime paths deliberately refuse to install schema.
$schemaKernel = new ConsoleKernel($projectRoot);
$schemaKernel->bootForSchemaSync();
$loader = $schemaKernel->getMigrationLoader();
$schemaKernel->getMigrator()->run($loader->loadAll(), $loader->loadAllV2());
$manager = $schemaKernel->getEntityTypeManager();
new EntitySchemaSyncRunner(
    $schemaKernel->getDatabase(),
    $manager->getFieldRegistry(),
)->run($manager->getDefinitions());

// HttpKernel is final and AbstractKernel::boot() is protected; HttpKernel
// boots through handle() in production. The smoke does not want to fake an
// HTTP request, so invoke boot() via reflection.
$kernel = new HttpKernel($projectRoot);
(new \ReflectionMethod($kernel, 'boot'))->invoke($kernel);

// Manifest compile + provider discovery + entity-type registration runs
// during boot(). This is the path that caught the alpha.149/.150 bugs.
$etm = $kernel->getEntityTypeManager();

if (!$etm->hasDefinition('user')) {
    fwrite(STDERR, "skeleton-smoke: 'user' entity type not registered after boot\n");
    fwrite(STDERR, "  registered: " . implode(', ', array_keys($etm->getDefinitions())) . "\n");
    exit(1);
}
echo "skeleton-smoke: entity type 'user' registered OK\n";

// Round-trip: save -> reload -> assert.
$repo = $etm->getRepository('user');

$marker = 'smoke-' . bin2hex(random_bytes(4));
$email = $marker . '@example.test';

$user = User::make([
    'name' => $marker,
    'mail' => $email,
    'permissions' => ['access user profiles'],
    'status' => 1,
    'created' => time(),
]);

$uid = $repo->save($user);

if ($uid <= 0) {
    fwrite(STDERR, "skeleton-smoke: save did not produce a uid (got {$uid})\n");
    exit(1);
}
echo "skeleton-smoke: saved user uid={$uid}\n";

$reloaded = $repo->find((string) $uid);
if (!$reloaded instanceof User) {
    fwrite(STDERR, "skeleton-smoke: reload returned " . get_debug_type($reloaded) . ", not User\n");
    exit(1);
}

$resolver = $kernel->getHttpServiceResolver();
$scope = $resolver->resolve(AccountFieldReadScopeInterface::class);
if (!$scope instanceof AccountFieldReadScopeInterface) {
    fwrite(STDERR, "skeleton-smoke: account field-read scope is not available\n");
    exit(1);
}

$principalFactory = $resolver->resolve(AccountPrincipalFactoryInterface::class);
if (!$principalFactory instanceof AccountPrincipalFactoryInterface) {
    fwrite(STDERR, "skeleton-smoke: account principal factory is not available\n");
    exit(1);
}

$profileViewer = $principalFactory->fromAccount($reloaded);
$reloadedName = $scope->run($profileViewer, static fn(): string => $reloaded->getName());

if ($reloadedName !== $marker) {
    fwrite(STDERR, "skeleton-smoke: round-trip mismatch\n");
    fwrite(STDERR, "  expected name={$marker}\n");
    fwrite(STDERR, "  got      name={$reloadedName}\n");
    exit(1);
}

echo "skeleton-smoke: round-trip OK (name={$marker})\n";
echo "skeleton-smoke: PASS\n";
exit(0);
