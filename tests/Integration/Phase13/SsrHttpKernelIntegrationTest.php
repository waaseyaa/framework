<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase13;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\DbInitHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

#[CoversNothing]
final class SsrHttpKernelIntegrationTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_ssr_http_' . uniqid();

        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        mkdir($this->projectRoot . '/templates', 0o755, true);
        mkdir($this->projectRoot . '/packages/demo/templates', 0o755, true);
        mkdir($this->projectRoot . '/packages/ssr/templates', 0o755, true);

        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);

        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', $this->buildConfigFile());

        file_put_contents(
            $this->projectRoot . '/templates/node.full.html.twig',
            <<<TWIG
                <article data-template="app-node-full">
                  <h1>{{ fields.title.formatted|raw }}</h1>
                  <time>{{ fields.created.formatted|raw }}</time>
                  <div class="author">{{ fields.uid.formatted|raw }}</div>
                  <div class="relationships">{{ relationship_navigation.entity.counts.total|default(0) }}</div>
                </article>
                TWIG,
        );

        file_put_contents(
            $this->projectRoot . '/templates/node.teaser.html.twig',
            <<<TWIG
                <article data-template="app-node-teaser">
                  <h2>{{ fields.title.formatted|raw }}</h2>
                </article>
                TWIG,
        );

        file_put_contents(
            $this->projectRoot . '/packages/demo/templates/node.full.html.twig',
            '<article data-template="package-node-full">PACKAGE TEMPLATE</article>',
        );

        file_put_contents(
            $this->projectRoot . '/packages/ssr/templates/entity.html.twig',
            '<article data-template="base-entity">{{ fields.title.formatted|raw }}</article>',
        );
        file_put_contents(
            $this->projectRoot . '/packages/ssr/templates/404.html.twig',
            '<!doctype html><html><body><h1>Not Found</h1><p>{{ path }}</p></body></html>',
        );

        $this->initializeFreshDatabase();
        $this->seedEntities();
        $this->materializeEndpointStatusForIsolation();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->projectRoot)) {
            return;
        }

        $this->checkpointSqliteDatabase($this->projectRoot . '/storage/waaseyaa.sqlite');
        $this->removeDirectory($this->projectRoot);
    }

    #[Test]
    public function rendersNodeHtmlWithFormattersAndTemplateOverride(): void
    {
        $response = $this->request('/node/1');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('data-template="app-node-full"', $response['body']);
        $this->assertStringNotContainsString('data-template="package-node-full"', $response['body']);
        $this->assertStringContainsString('Water Is Life', $response['body']);
        $this->assertStringContainsString('2025-01-01', $response['body']);
        $this->assertStringContainsString('<a href="/user/7">Author</a>', $response['body']);
    }

    #[Test]
    public function resolvesPathAliasAndRendersSameEntity(): void
    {
        $response = $this->request('/teaching/water-is-life');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Water Is Life', $response['body']);
    }

    #[Test]
    public function freshInstallRendersRelationshipTraversalContext(): void
    {
        $pdo = new \PDO('sqlite:' . $this->projectRoot . '/storage/waaseyaa.sqlite');
        $columns = $pdo->query("PRAGMA table_info('relationship')")->fetchAll(\PDO::FETCH_COLUMN, 1);
        $this->assertContains('_data', $columns);
        $this->assertNotContains('from_entity_type', $columns, 'Fresh db:init must exercise the sql-blob shape.');

        $response = $this->request('/node/1');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Water Is Life', $response['body']);
        $this->assertStringContainsString('<div class="relationships">1</div>', $response['body']);
    }

    #[Test]
    public function supportsTeaserAndFullViewModesViaQueryParameter(): void
    {
        $full = $this->request('/node/1?view_mode=full');
        $teaser = $this->request('/node/1?view_mode=teaser');

        $this->assertStringContainsString('data-template="app-node-full"', $full['body']);
        $this->assertStringContainsString('2025-01-01', $full['body']);
        $this->assertStringContainsString('/user/7', $full['body']);

        $this->assertStringContainsString('data-template="app-node-teaser"', $teaser['body']);
        $this->assertStringNotContainsString('/user/7', $teaser['body']);
        $this->assertStringNotContainsString('2025-01-01', $teaser['body']);
    }

    #[Test]
    public function unknownPathReturns404Html(): void
    {
        $response = $this->request('/does-not-exist');

        $this->assertSame(404, $response['status']);
        $this->assertStringContainsString('<h1>Not Found</h1>', $response['body']);
        $this->assertStringNotContainsString('"jsonapi"', $response['body']);
    }

    #[Test]
    public function unpublishedWorkflowStatesAreHiddenFromPublicSsr(): void
    {
        // Since fix #322: access-denied returns 403 (not 404) so the HTTP
        // response correctly distinguishes forbidden from not-found.
        $draft = $this->request('/node/2');
        $review = $this->request('/node/3');
        $archived = $this->request('/node/4');

        $this->assertSame(403, $draft['status']);
        $this->assertSame(403, $review['status']);
        $this->assertSame(403, $archived['status']);
    }

    #[Test]
    public function unauthenticatedPreviewQueryDoesNotBypassVisibility(): void
    {
        // Since fix #322: access-denied returns 403 (not 404).
        $draftPreview = $this->request('/node/2?preview=1');
        $reviewPreview = $this->request('/node/3?preview=true');

        $this->assertSame(403, $draftPreview['status']);
        $this->assertSame(403, $reviewPreview['status']);
    }

    #[Test]
    public function entity_save_invalidates_render_cache_for_subsequent_http_request(): void
    {
        $first = $this->request('/node/1');
        $this->assertSame(200, $first['status']);
        $this->assertStringContainsString('Water Is Life', $first['body']);

        $this->runEntityFixtureAction('update-node-title', 'CHANGED TITLE');

        $second = $this->request('/node/1');
        $this->assertSame(200, $second['status']);
        $this->assertStringContainsString('CHANGED TITLE', $second['body']);
        $this->assertStringNotContainsString('Water Is Life', $second['body']);
    }

    #[Test]
    public function previewRequestDoesNotWriteOrReadPublicRenderCache(): void
    {
        $previewFirst = $this->request('/node/1?preview=1');
        $this->assertSame(200, $previewFirst['status']);
        $this->assertStringContainsString('Water Is Life', $previewFirst['body']);

        $this->runEntityFixtureAction('update-node-title', 'PREVIEW CHANGED TITLE');

        $previewSecond = $this->request('/node/1?preview=1');
        $this->assertSame(200, $previewSecond['status']);
        $this->assertStringContainsString('PREVIEW CHANGED TITLE', $previewSecond['body']);
    }

    private function seedEntities(): void
    {
        $this->runEntityFixtureAction('seed');
    }

    private function initializeFreshDatabase(): void
    {
        $handler = new DbInitHandler($this->projectRoot);
        $command = new HandlerCommand(
            name: 'db:init',
            description: 'Initialize a fresh database.',
            options: [
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None, description: 'Dry run.'),
                new HandlerOption(name: 'no-sync-schema', mode: HandlerOptionMode::None, description: 'Skip schema sync.'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Not found: {$id}");
            }
            public function has(string $id): bool
            {
                return false;
            }
        };
        $tester = CliTester::for($command, $container);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode(), "Fresh db:init failed.\n" . $tester->getStderr());
        $this->assertStringContainsString('Created database', $tester->getStdout());
    }

    /**
     * Keep this #1984 regression independent of #1982's fresh-boot field
     * hydration failure: endpoint publication is not the behavior under test.
     */
    private function materializeEndpointStatusForIsolation(): void
    {
        $pdo = new \PDO('sqlite:' . $this->projectRoot . '/storage/waaseyaa.sqlite');
        $pdo->exec('ALTER TABLE node ADD COLUMN status INTEGER NOT NULL DEFAULT 0');
        $pdo->exec("ALTER TABLE node ADD COLUMN workflow_state TEXT NOT NULL DEFAULT 'draft'");
        $pdo->exec("UPDATE node SET status = 1, workflow_state = 'published' WHERE nid IN (1, 5)");
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
        $this->assertNotNull($output, 'Kernel runner produced no output.');

        $lines = array_values(array_filter(
            preg_split('/\R/', trim((string) $output)) ?: [],
            static fn(string $line): bool => trim($line) !== '',
        ));
        $jsonPayload = $lines !== [] ? $lines[count($lines) - 1] : '';
        $payload = json_decode($jsonPayload, true);
        $this->assertIsArray($payload, 'Kernel runner returned invalid JSON: ' . $output);

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
                'app' => ['url' => 'http://localhost', 'name' => 'Waaseyaa Test'],
                'cors_origins' => ['http://localhost:3000'],
                'ssr' => [
                    'theme' => '',
                    'cache_max_age' => 300,
                ],
                'view_modes' => [
                    'node' => [
                        'full' => [
                            'title' => ['formatter' => 'string', 'weight' => 0],
                            'created' => ['formatter' => 'datetime', 'settings' => ['format' => 'Y-m-d'], 'weight' => 1],
                            'uid' => ['formatter' => 'entity_reference', 'settings' => ['label' => 'Author', 'url_pattern' => '/user/{id}'], 'weight' => 2],
                        ],
                        'teaser' => [
                            'title' => ['formatter' => 'string', 'weight' => 0],
                        ],
                    ],
                ],
            ];
            PHP;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $itemPath = $item->getPathname();
            if ($item->isLink() || $item->isFile()) {
                $this->removeFile($itemPath);
                continue;
            }

            $this->removeEmptyDirectory($itemPath);
        }

        $this->removeEmptyDirectory($path);
    }

    private function checkpointSqliteDatabase(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        try {
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $pdo->exec('PRAGMA journal_mode = DELETE');
            $pdo = null;
        } catch (\Throwable) {
            // Teardown still performs a strict delete below; any persistent lock
            // will surface there with the original filesystem error.
        }
    }

    private function removeFile(string $path): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (!is_file($path) && !is_link($path)) {
                return;
            }
            if (@unlink($path)) {
                return;
            }
            usleep(50_000);
            clearstatcache(true, $path);
        }

        unlink($path);
    }

    private function removeEmptyDirectory(string $path): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            if (!is_dir($path)) {
                return;
            }
            if (@rmdir($path)) {
                return;
            }
            usleep(50_000);
            clearstatcache(true, $path);
        }

        rmdir($path);
    }

    private function runEntityFixtureAction(string $action, string $value = ''): void
    {
        $runner = $this->repoRoot . '/tests/Integration/Phase13/Fixtures/ssr_entity_runner.php';
        $command = sprintf(
            '%s %s %s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($runner),
            escapeshellarg($this->repoRoot),
            escapeshellarg($this->projectRoot),
            escapeshellarg($action),
            escapeshellarg($value),
        );

        $output = shell_exec($command);
        $this->assertNotNull($output, 'SSR entity runner produced no output.');

        $lines = array_values(array_filter(
            preg_split('/\R/', trim((string) $output)) ?: [],
            static fn(string $line): bool => trim($line) !== '',
        ));
        $jsonPayload = $lines !== [] ? $lines[count($lines) - 1] : '';
        $payload = json_decode($jsonPayload, true);

        $this->assertIsArray($payload, 'SSR entity runner returned invalid JSON: ' . $output);
        $this->assertSame(true, $payload['ok'] ?? false, 'SSR entity runner failed: ' . $output);
    }
}
