<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Oidc;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

/**
 * End-to-end wiring test for /authorize. Boots a fresh kernel in a subprocess,
 * so the assertions cover: route registration, controller DI resolution,
 * OidcClientLookup binding against oidc_client storage, and the anonymous →
 * login redirect branch of AuthorizeController.
 *
 * The header list is asserted separately in AuthorizeControllerTest (under CLI
 * `headers_list()` is empty, so per-request assertions stay there).
 */
#[CoversNothing]
final class OidcAuthorizeIntegrationTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_oidc_authorize_' . uniqid();

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
    public function anonymousGetAuthorizeRedirectsToLogin(): void
    {
        $response = $this->request(
            '/oidc/authorize?client_id=minoo-web&redirect_uri=https%3A%2F%2Fminoo.test%2Fcallback'
            . '&response_type=code&scope=openid%20profile&state=xyz'
            . '&code_challenge=abc&code_challenge_method=S256',
        );

        self::assertSame(302, $response['status']);
        // Symfony's RedirectResponse emits a meta-refresh + anchor HTML body as
        // a fallback for clients that don't honour the Location header. That
        // body embeds the final URL, which is all we can assert under the CLI
        // runner (headers_list() is empty here — header assertions live in the
        // AuthorizeController unit tests).
        self::assertStringContainsString('/login?return_to=', $response['body']);
        self::assertStringContainsString('client_id%3Dminoo-web', $response['body']);
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
                'app' => ['url' => 'http://localhost', 'name' => 'Waaseyaa Oidc Authorize Test'],
                'cors_origins' => ['http://localhost:3000'],
                'oidc' => [
                    'issuer' => 'https://id.example',
                    'login_path' => '/login',
                ],
            ];
            PHP;
    }
}
