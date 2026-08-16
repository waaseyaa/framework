<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Oidc;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

/**
 * End-to-end wiring test for /token. Boots a fresh kernel in a subprocess:
 *
 *   1. Issues an authorization code via the real DatabaseAuthorizationCodeRepository.
 *   2. Dispatches POST /token through the full middleware pipeline.
 *   3. Asserts the JSON response carries id_token, access_token, token_type,
 *      expires_in, and that the ID token signature verifies against the
 *      configured RS256 public key.
 *
 * The PKCE verification, client authentication, and error branches are covered
 * in detail by TokenControllerTest; this test proves the pieces are wired in
 * the kernel.
 */
#[CoversNothing]
final class OidcTokenIntegrationTest extends TestCase
{
    private const CLIENT_ID = 'minoo-web';
    private const REDIRECT_URI = 'https://minoo.test/callback';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const ISSUER = 'https://id.example';

    private string $repoRoot;
    private string $projectRoot;
    private string|false $originalApplicationSecret = false;
    private string $applicationSecret = '';

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_oidc_token_' . uniqid();
        $this->originalApplicationSecret = getenv('WAASEYAA_APP_SECRET');
        $this->applicationSecret = 'base64:' . base64_encode(random_bytes(32));
        putenv('WAASEYAA_APP_SECRET=' . $this->applicationSecret);

        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);

        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);

        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', $this->buildConfigFile());
        $database = \Waaseyaa\Database\DBALDatabase::createSqlite($this->projectRoot . '/storage/waaseyaa.sqlite');
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::auth($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::audit($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::broadcast($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::cache($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::oidc($database);
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::entitiesForProject($this->projectRoot);
        $applicationSecret = \Waaseyaa\Foundation\Security\ApplicationSecret::fromEnvironmentValue(
            $this->applicationSecret,
            'testing',
        );
        new \Waaseyaa\Oidc\Key\SigningKeyRepository(
            $database,
            $applicationSecret->derive(
                \Waaseyaa\Foundation\Security\ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
            ),
        )->initialize();
    }

    protected function tearDown(): void
    {
        if ($this->originalApplicationSecret === false) {
            putenv('WAASEYAA_APP_SECRET');
        } else {
            putenv('WAASEYAA_APP_SECRET=' . $this->originalApplicationSecret);
        }
        if (!is_dir($this->projectRoot)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
                continue;
            }
            rmdir($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function tokenEndpointExchangesCodeForSignedIdToken(): void
    {
        $result = $this->runTokenFlow(nonce: 'nonce-xyz');

        self::assertSame(
            200,
            $result['status'],
            'Body: ' . $result['body'] . "\nRunner diagnostics:\n" . $result['diagnostics'],
        );
        self::assertStringContainsString('application/json', $result['headers']['content-type']);
        self::assertStringContainsString('no-store', $result['headers']['cache-control']);

        $payload = json_decode($result['body'], true);
        self::assertIsArray($payload);
        self::assertSame('Bearer', $payload['token_type']);
        self::assertSame(3600, $payload['expires_in']);
        self::assertIsString($payload['access_token']);
        self::assertNotEmpty($payload['access_token']);
        self::assertIsString($payload['id_token']);

        $claims = $this->verifyAndDecodeIdToken($payload['id_token'], $result['signing_public_key']);
        self::assertSame(self::ISSUER, $claims['iss']);
        self::assertSame(self::CLIENT_ID, $claims['aud']);
        self::assertSame('nonce-xyz', $claims['nonce']);
        self::assertArrayHasKey('exp', $claims);
        self::assertArrayHasKey('iat', $claims);
        self::assertArrayHasKey('auth_time', $claims);
        self::assertArrayHasKey('sub', $claims);
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string,signing_public_key:string,diagnostics:string}
     */
    private function runTokenFlow(?string $nonce = null): array
    {
        $runner = $this->repoRoot . '/tests/Integration/Oidc/Fixtures/token_flow_runner.php';
        $command = sprintf(
            '%s %s %s %s %s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($runner),
            escapeshellarg($this->repoRoot),
            escapeshellarg($this->projectRoot),
            escapeshellarg(self::CLIENT_ID),
            escapeshellarg(self::REDIRECT_URI),
            escapeshellarg(self::CHALLENGE),
            escapeshellarg(self::VERIFIER),
            escapeshellarg((string) $nonce),
        );

        $output = shell_exec($command);
        self::assertNotNull($output, 'Runner produced no output.');

        $lines = array_values(array_filter(
            preg_split('/\R/', trim((string) $output)) ?: [],
            static fn(string $line): bool => trim($line) !== '',
        ));
        $jsonPayload = $lines !== [] ? $lines[count($lines) - 1] : '';
        $payload = json_decode($jsonPayload, true);
        self::assertIsArray($payload, 'Runner returned invalid JSON: ' . $output);

        return [
            'status' => (int) ($payload['status'] ?? 0),
            'headers' => is_array($payload['headers'] ?? null) ? $payload['headers'] : [],
            'body' => (string) ($payload['body'] ?? ''),
            'signing_public_key' => (string) ($payload['signing_public_key'] ?? ''),
            'diagnostics' => implode("\n", array_slice($lines, 0, -1)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyAndDecodeIdToken(string $jwt, string $signingPublicKeyPem): array
    {
        self::assertNotSame('', $signingPublicKeyPem, 'Runner did not surface the active signing key.');

        $parts = explode('.', $jwt);
        self::assertCount(3, $parts, 'ID token must have 3 segments');

        $signingInput = $parts[0] . '.' . $parts[1];
        $signature = $this->base64UrlDecode($parts[2]);

        self::assertSame(
            1,
            openssl_verify($signingInput, $signature, $signingPublicKeyPem, OPENSSL_ALGO_SHA256),
            'ID token signature did not verify against the active signing key',
        );

        $claims = json_decode($this->base64UrlDecode($parts[1]), true);
        self::assertIsArray($claims);

        return $claims;
    }

    private function base64UrlDecode(string $encoded): string
    {
        $padded = str_pad($encoded, (int) (ceil(strlen($encoded) / 4) * 4), '=', STR_PAD_RIGHT);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        self::assertIsString($decoded);

        return $decoded;
    }

    private function buildConfigFile(): string
    {
        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';

        return <<<PHP
            <?php

            declare(strict_types=1);

            return [
                'database' => '{$databasePath}',
                'environment' => 'testing',
                'app' => ['url' => 'http://localhost', 'name' => 'Waaseyaa Oidc Token Test'],
                'cors_origins' => ['http://localhost:3000'],
                'oidc' => [
                    'issuer' => '{$this->issuer()}',
                    'clients' => [
                        'minoo-web' => [
                            'name' => 'Minoo',
                            'redirect_uris' => ['https://minoo.test/callback'],
                            'scopes' => ['openid'],
                            'grant_types' => ['authorization_code'],
                            'is_confidential' => false,
                        ],
                    ],
                ],
            ];
            PHP;
    }

    private function issuer(): string
    {
        return self::ISSUER;
    }
}
