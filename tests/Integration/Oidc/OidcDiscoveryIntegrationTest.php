<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Oidc;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

#[CoversNothing]
final class OidcDiscoveryIntegrationTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_oidc_discovery_' . uniqid();

        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);

        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);

        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', $this->buildConfigFile());
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
    public function discoveryEndpointReturnsOidcMetadataWithConfiguredIssuer(): void
    {
        $response = $this->request('/.well-known/openid-configuration');

        self::assertSame(200, $response['status']);

        $body = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://id.example', $body['issuer']);
        self::assertSame('https://id.example/oidc/authorize', $body['authorization_endpoint']);
        self::assertSame('https://id.example/oidc/token', $body['token_endpoint']);
        self::assertSame('https://id.example/oidc/userinfo', $body['userinfo_endpoint']);
        self::assertSame('https://id.example/.well-known/jwks.json', $body['jwks_uri']);
        self::assertContains('code', $body['response_types_supported']);
        self::assertContains('RS256', $body['id_token_signing_alg_values_supported']);
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
                'app' => ['url' => 'http://localhost', 'name' => 'Waaseyaa Oidc Test'],
                'cors_origins' => ['http://localhost:3000'],
                'oidc' => ['issuer' => 'https://id.example'],
            ];
            PHP;
    }
}
