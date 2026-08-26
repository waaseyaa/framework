<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Oidc;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

#[CoversNothing]
final class OidcJwksIntegrationTest extends TestCase
{
    private string $repoRoot = '';
    private string $projectRoot = '';
    private string|false $originalApplicationSecret = false;
    private string $applicationSecret = '';

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_oidc_jwks_' . uniqid();
        $this->originalApplicationSecret = getenv('WAASEYAA_APP_SECRET');
        $this->applicationSecret = 'base64:' . base64_encode(random_bytes(32));
        putenv('WAASEYAA_APP_SECRET=' . $this->applicationSecret);

        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);

        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);

        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', $this->buildConfigFile());
        $database = \Waaseyaa\Database\DBALDatabase::createSqlite($this->projectRoot . '/storage/waaseyaa.sqlite');
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::foundation($database);
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

        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function jwksEndpointReturnsRsaJwkForActiveSigningKey(): void
    {
        // Initialization is explicit in setUp; the public read path cannot create
        // or replace signing authority.
        $response = $this->request('/.well-known/jwks.json');

        self::assertSame(200, $response['status']);

        $body = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('keys', $body);
        self::assertGreaterThanOrEqual(1, count($body['keys']));

        $jwk = $body['keys'][0];
        self::assertSame('RSA', $jwk['kty']);
        self::assertSame('sig', $jwk['use']);
        self::assertSame('RS256', $jwk['alg']);
        self::assertIsString($jwk['kid']);
        self::assertNotSame('', $jwk['kid']);
        // The modulus/exponent must be non-empty base64url with no padding so RPs
        // can reconstruct the public key.
        foreach (['n', 'e'] as $component) {
            self::assertIsString($jwk[$component]);
            self::assertNotSame('', $jwk[$component]);
            self::assertSame(
                1,
                preg_match('/^[A-Za-z0-9_-]+$/', $jwk[$component]),
                "JWK component {$component} must be base64url-encoded",
            );
        }
    }

    #[Test]
    public function anonymousJwksReadCannotInitializeAnEmptyLifecycle(): void
    {
        $database = \Waaseyaa\Database\DBALDatabase::createSqlite(
            $this->projectRoot . '/storage/waaseyaa.sqlite',
        );
        $database->getConnection()->executeStatement('DELETE FROM oidc_signing_key');
        $database->getConnection()->close();

        $response = $this->request('/.well-known/jwks.json');

        self::assertSame(500, $response['status']);
        $database = \Waaseyaa\Database\DBALDatabase::createSqlite(
            $this->projectRoot . '/storage/waaseyaa.sqlite',
        );
        self::assertSame(
            0,
            (int) $database->getConnection()->fetchOne('SELECT COUNT(*) FROM oidc_signing_key'),
        );
    }

    /**
     * @return array{status:int,headers:list<string>,body:string}
     */
    private function request(string $uri, string $method = 'GET'): array
    {
        $runner = $this->repoRoot . '/tests/Integration/Phase13/Fixtures/http_kernel_runner.php';
        $command = sprintf(
            '%s %s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($runner),
            escapeshellarg($this->repoRoot),
            escapeshellarg($this->projectRoot),
            escapeshellarg($method),
            escapeshellarg($uri),
        );

        $output = shell_exec($command);
        self::assertNotNull($output, 'Kernel runner produced no output.');

        $splitOutput = preg_split('/\R/', trim((string) $output));
        $lines = array_values(array_filter(
            is_array($splitOutput) ? $splitOutput : [],
            static fn(string $line): bool => trim($line) !== '',
        ));
        $jsonPayload = $lines !== [] ? $lines[count($lines) - 1] : '';
        $payload = json_decode($jsonPayload, true);
        self::assertIsArray($payload, 'Kernel runner returned invalid JSON: ' . $output);

        return [
            'status' => (int) ($payload['status'] ?? 0),
            'headers' => is_array($payload['headers'] ?? null) ? array_values($payload['headers']) : [],
            'body' => (string) ($payload['body'] ?? ''),
        ];
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
                'app' => ['url' => 'http://localhost', 'name' => 'Waaseyaa Oidc JWKS Test'],
                'cors_origins' => ['http://localhost:3000'],
                'oidc' => [
                    'issuer' => 'https://id.example',
                ],
            ];
            PHP;
    }
}
