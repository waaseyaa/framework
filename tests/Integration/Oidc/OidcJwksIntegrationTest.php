<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Oidc;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

#[CoversNothing]
final class OidcJwksIntegrationTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_oidc_jwks_' . uniqid();

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
    }

    protected function tearDown(): void
    {
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
    public function jwksEndpointReturnsRsaJwkForActiveSigningKey(): void
    {
        // WP04 moved signing-key material into the database: SigningKeyRepository
        // auto-generates an RSA key (with a generated UUID kid) on first boot when
        // the table is empty, and the JWKS endpoint serves all active keys. So we
        // assert the JWK is structurally well-formed rather than pinning it to a
        // pre-seeded public key.
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

        $lines = array_values(array_filter(
            preg_split('/\R/', trim((string) $output)) ?: [],
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
                'environment' => 'local',
                'app' => ['url' => 'http://localhost', 'name' => 'Waaseyaa Oidc JWKS Test'],
                'cors_origins' => ['http://localhost:3000'],
                'oidc' => [
                    'issuer' => 'https://id.example',
                ],
            ];
            PHP;
    }
}
