<?php

declare(strict_types=1);

/**
 * Installed Community Events registered-role provisioning acceptance (#3046).
 *
 * `exercise` drives the public command in fresh child processes. `inspect`
 * opens a new kernel process and compares stored authorization with the actual
 * generated provider and the kernel's validated RoleRepository.
 *
 * Usage: php community-events-registered-role-provisioning.php <exercise|inspect> <consumer-root>
 */

use Symfony\Component\Process\Process;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\CLI\Handler\UserProvisionRegisteredHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\User\RoleRepository;

if ($argc !== 3 || !in_array($argv[1], ['exercise', 'inspect'], true)) {
    fwrite(STDERR, "Usage: php community-events-registered-role-provisioning.php <exercise|inspect> <consumer-root>\n");
    exit(2);
}

$mode = $argv[1];
$consumer = realpath($argv[2]);
if (!is_string($consumer) || !is_file($consumer . '/vendor/autoload.php')) {
    fwrite(STDERR, "The installed consumer root is unavailable.\n");
    exit(2);
}
require $consumer . '/vendor/autoload.php';

if ($mode === 'exercise') {
    $password = 'acceptance-' . bin2hex(random_bytes(24));
    $accounts = [
        ['community-owner', 'owner@example.test', 'contributor'],
        ['community-reviewer', 'reviewer@example.test', 'reviewer'],
        ['community-administrator', 'administrator@example.test', 'event_administrator'],
    ];
    $created = [];
    foreach ($accounts as [$username, $email, $role]) {
        $result = runProvisioningCommand($consumer, $username, $email, $role, $password);
        requireResult($result, 'created', 'account_created');
        $created[$role] = $result['account_id'];
    }

    $retry = runProvisioningCommand($consumer, 'community-owner', 'owner@example.test', 'contributor', $password);
    requireResult($retry, 'existing', 'account_already_matches');
    if ($retry['account_id'] !== $created['contributor']) {
        throw new RuntimeException('Exact retry changed the account identity.');
    }

    $conflict = runProvisioningCommand($consumer, 'community-owner', 'different@example.test', 'contributor', $password);
    requireResult($conflict, 'refused', 'identity_conflict');
    $invalid = runProvisioningCommand($consumer, 'community-invalid', 'invalid@example.test', 'not_a_registered_role', $password);
    requireResult($invalid, 'refused', 'unknown_role');
    $reserved = runProvisioningCommand($consumer, 'community-reserved', 'reserved@example.test', 'administrator', $password);
    requireResult($reserved, 'refused', 'reserved_role');

    $retryAfterRefusals = runProvisioningCommand($consumer, 'community-owner', 'owner@example.test', 'contributor', $password);
    requireResult($retryAfterRefusals, 'existing', 'account_already_matches');
    if ($retryAfterRefusals['account_id'] !== $created['contributor']) {
        throw new RuntimeException('A refused request changed the existing account.');
    }

    echo CanonicalJson::encode([
        'created_roles' => array_keys($created),
        'refusals' => [$conflict['code'], $invalid['code'], $reserved['code']],
        'retry' => $retryAfterRefusals['status'],
        'status' => 'passed',
    ]) . "\n";
    exit(0);
}

$kernel = new ConsoleKernel($consumer);
$kernel->bootForCli();
$container = $kernel->buildHandlerContainer();
$roles = $container->get(RoleRepository::class);
$internal = $container->get(UserInternalFieldReaderInterface::class);
if (!$roles instanceof RoleRepository || !$internal instanceof UserInternalFieldReaderInterface) {
    throw new RuntimeException('The installed application did not expose canonical user authorization services.');
}

$generated = RoleRepository::fromProviders([new App\Provider\ApplicationBlueprintGovernanceServiceProvider()]);
$repository = $kernel->getEntityTypeManager()->getRepository('user');
$expected = [
    'community-owner' => 'contributor',
    'community-reviewer' => 'reviewer',
    'community-administrator' => 'event_administrator',
];
$observed = [];
foreach ($expected as $username => $roleId) {
    $role = $generated->get($roleId);
    $kernelRole = $roles->get($roleId);
    if ($role === null || $kernelRole === null || $role->permissions !== $kernelRole->permissions) {
        throw new RuntimeException('Generated and kernel role authority differ.');
    }
    $ids = $repository->getQuery()
        ->accessCheck(false)
        ->condition('name', $username)
        ->range(0, 2)
        ->execute();
    if (count($ids) !== 1) {
        throw new RuntimeException('The provisioned account identity is missing or ambiguous.');
    }
    $user = $repository->find((string) $ids[0]);
    if (!$user instanceof EntityInterface) {
        throw new RuntimeException('The provisioned account could not be loaded.');
    }
    $authorization = $internal->maintenanceAuthorization($user);
    if ($authorization->roles !== [$roleId] || $authorization->permissions !== $role->permissions) {
        throw new RuntimeException('Stored authorization differs from the generated role.');
    }
    $observed[$roleId] = count($authorization->permissions);
}

echo CanonicalJson::encode(['permission_counts' => $observed, 'status' => 'passed']) . "\n";

/** @return array<string, mixed> */
function runProvisioningCommand(
    string $consumer,
    string $username,
    string $email,
    string $role,
    #[\SensitiveParameter]
    string $password,
): array {
    $request = CanonicalJson::encode([
        'email' => $email,
        'password' => $password,
        'role' => $role,
        'schema' => UserProvisionRegisteredHandler::INPUT_SCHEMA,
        'username' => $username,
        'version' => 1,
    ]) . "\n";
    $command = [PHP_BINARY, $consumer . '/vendor/bin/waaseyaa', 'user:provision-registered'];
    $environment = ['APP_ENV' => 'local'];
    $process = new Process($command, $consumer, $environment, input: $request, timeout: 30);
    $exit = $process->run();
    $stdout = $process->getOutput();
    $stderr = $process->getErrorOutput();
    if (
        str_contains($process->getCommandLine(), $password)
        || in_array($password, $environment, true)
        || str_contains($stdout, $password)
        || str_contains($stderr, $password)
    ) {
        throw new RuntimeException('Credential bytes escaped the private stdin boundary.');
    }
    if ($stderr !== '') {
        throw new RuntimeException('The provisioning command wrote diagnostic output.');
    }
    try {
        $result = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new RuntimeException('The provisioning command returned invalid JSON.');
    }
    if (!is_array($result) || !hash_equals(CanonicalJson::encode($result) . "\n", $stdout)) {
        throw new RuntimeException('The provisioning command returned a non-canonical result.');
    }
    $expectedExit = match ($result['status'] ?? null) {
        'created', 'existing' => 0,
        'refused' => 1,
        'uncertain' => 2,
        default => -1,
    };
    if ($exit !== $expectedExit) {
        throw new RuntimeException('The provisioning command exit did not match its result.');
    }

    return $result;
}

/** @param array<string, mixed> $result */
function requireResult(array $result, string $status, string $code): void
{
    if (($result['status'] ?? null) !== $status || ($result['code'] ?? null) !== $code) {
        throw new RuntimeException('The provisioning command returned an unexpected closed result.');
    }
}
