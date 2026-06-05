<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Oidc\Repository\AuthorizationCodeRepositoryInterface;
use Waaseyaa\Oidc\Token\KeyMaterialProviderInterface;
use Waaseyaa\User\DevAdminAccount;

/**
 * Exercises the /token flow end-to-end in a subprocess.
 *
 * Phase 1: boot the kernel (via reflection since boot() is protected), then
 * resolve AuthorizationCodeRepositoryInterface and issue a code bound to the
 * passed client_id, redirect_uri, and challenge.
 *
 * Phase 2: populate globals to look like POST /token with the freshly issued
 * code + matching verifier, call handle(), and emit the resulting response as
 * JSON on stdout for the PHPUnit test to assert against.
 */
if ($argc < 7) {
    fwrite(STDERR, "Usage: php token_flow_runner.php <repo_root> <project_root> <client_id> <redirect_uri> <code_challenge> <code_verifier> [nonce]\n");
    exit(1);
}

$repoRoot = (string) $argv[1];
$projectRoot = (string) $argv[2];
$clientId = (string) $argv[3];
$redirectUri = (string) $argv[4];
$codeChallenge = (string) $argv[5];
$codeVerifier = (string) $argv[6];
$nonce = isset($argv[7]) && $argv[7] !== '' ? (string) $argv[7] : null;

require $repoRoot . '/vendor/autoload.php';

$kernel = new HttpKernel($projectRoot);

$bootMethod = new ReflectionClass(HttpKernel::class)->getMethod('boot');
$bootMethod->invoke($kernel);

$resolver = $kernel->getHttpServiceResolver();
$codeRepository = $resolver->resolve(AuthorizationCodeRepositoryInterface::class);
if (!$codeRepository instanceof AuthorizationCodeRepositoryInterface) {
    fwrite(STDERR, "Failed to resolve AuthorizationCodeRepositoryInterface\n");
    exit(1);
}

$issued = $codeRepository->issue(
    clientId: $clientId,
    account: new DevAdminAccount(),
    redirectUri: $redirectUri,
    scopes: ['openid'],
    codeChallenge: $codeChallenge,
    codeChallengeMethod: 'S256',
    nonce: $nonce,
);

// Phase 2: set globals to simulate POST /token and dispatch through kernel.
$_GET = [];
$_POST = [
    'grant_type' => 'authorization_code',
    'code' => $issued->code,
    'redirect_uri' => $redirectUri,
    'code_verifier' => $codeVerifier,
    'client_id' => $clientId,
];
$_COOKIE = [];
$_FILES = [];
$_REQUEST = $_POST;
$_SERVER = [
    'REQUEST_METHOD' => 'POST',
    'REQUEST_URI' => '/oidc/token',
    'QUERY_STRING' => '',
    'HTTP_HOST' => 'localhost',
    'SERVER_NAME' => 'localhost',
    'SERVER_PORT' => '80',
    'HTTPS' => 'off',
    'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
];

$response = $kernel->handle();

// WP04: the ID token is signed by the DB-backed active signing key (auto-generated
// on first boot), not by any file-configured key. Surface its public PEM so the
// test can verify the JWT signature against the key that actually signed it.
$signingPublicKey = '';
$keyProvider = $resolver->resolve(KeyMaterialProviderInterface::class);
if ($keyProvider instanceof KeyMaterialProviderInterface) {
    $signingPublicKey = $keyProvider->currentKey()->publicKeyPem;
}

echo json_encode([
    'status' => $response->getStatusCode(),
    'headers' => [
        'content-type' => (string) $response->headers->get('Content-Type'),
        'cache-control' => (string) $response->headers->get('Cache-Control'),
        'pragma' => (string) $response->headers->get('Pragma'),
    ],
    'body' => (string) $response->getContent(),
    'signing_public_key' => $signingPublicKey,
], JSON_THROW_ON_ERROR);
