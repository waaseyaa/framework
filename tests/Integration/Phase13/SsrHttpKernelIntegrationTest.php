<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase13;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Tests\Support\WorkflowFixturePack;

#[CoversNothing]
final class SsrHttpKernelIntegrationTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_ssr_http_' . uniqid();

        mkdir($this->projectRoot . '/config', 0755, true);
        mkdir($this->projectRoot . '/storage', 0755, true);
        mkdir($this->projectRoot . '/templates', 0755, true);
        mkdir($this->projectRoot . '/packages/demo/templates', 0755, true);
        mkdir($this->projectRoot . '/packages/ssr/templates', 0755, true);

        $this->assertTrue(symlink($this->repoRoot . '/vendor', $this->projectRoot . '/vendor'));

        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', $this->buildConfigFile());

        file_put_contents(
            $this->projectRoot . '/templates/node.full.html.twig',
            <<<TWIG
<article data-template="app-node-full">
  <h1>{{ fields.title.formatted|raw }}</h1>
  <time>{{ fields.created.formatted|raw }}</time>
  <div class="author">{{ fields.uid.formatted|raw }}</div>
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

        $this->seedEntities();
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
        $draft = $this->request('/node/2');
        $review = $this->request('/node/3');
        $archived = $this->request('/node/4');

        $this->assertSame(404, $draft['status']);
        $this->assertSame(404, $review['status']);
        $this->assertSame(404, $archived['status']);
    }

    #[Test]
    public function unauthenticatedPreviewQueryDoesNotBypassVisibility(): void
    {
        $draftPreview = $this->request('/node/2?preview=1');
        $reviewPreview = $this->request('/node/3?preview=true');

        $this->assertSame(404, $draftPreview['status']);
        $this->assertSame(404, $reviewPreview['status']);
    }

    #[Test]
    public function secondRequestUsesRenderCacheForSameEntity(): void
    {
        $first = $this->request('/node/1');
        $this->assertSame(200, $first['status']);
        $this->assertStringContainsString('Water Is Life', $first['body']);

        $kernel = $this->bootKernel();
        $storage = $kernel->getEntityTypeManager()->getStorage('node');
        $node = $storage->load(1);
        self::assertNotNull($node);
        $node->set('title', 'CHANGED TITLE');
        $storage->save($node);

        $second = $this->request('/node/1');
        $this->assertSame(200, $second['status']);
        $this->assertStringContainsString('Water Is Life', $second['body']);
        $this->assertStringNotContainsString('CHANGED TITLE', $second['body']);
    }

    #[Test]
    public function previewRequestDoesNotWriteOrReadPublicRenderCache(): void
    {
        $previewFirst = $this->request('/node/1?preview=1');
        $this->assertSame(200, $previewFirst['status']);
        $this->assertStringContainsString('Water Is Life', $previewFirst['body']);

        $kernel = $this->bootKernel();
        $storage = $kernel->getEntityTypeManager()->getStorage('node');
        $node = $storage->load(1);
        self::assertNotNull($node);
        $node->set('title', 'PREVIEW CHANGED TITLE');
        $storage->save($node);

        $previewSecond = $this->request('/node/1?preview=1');
        $this->assertSame(200, $previewSecond['status']);
        $this->assertStringContainsString('PREVIEW CHANGED TITLE', $previewSecond['body']);
    }

    private function seedEntities(): void
    {
        $kernel = $this->bootKernel();

        $nodeStorage = $kernel->getEntityTypeManager()->getStorage('node');
        foreach (WorkflowFixturePack::editorialNodesForSsr() as $fixture) {
            $node = $nodeStorage->create($fixture);
            $nodeStorage->save($node);
        }

        $pathAliasStorage = $kernel->getEntityTypeManager()->getStorage('path_alias');
        foreach (WorkflowFixturePack::pathAliasesForSsr() as $aliasFixture) {
            $alias = $pathAliasStorage->create($aliasFixture);
            $pathAliasStorage->save($alias);
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
        $databasePath = $this->projectRoot . '/waaseyaa.sqlite';

        return <<<PHP
<?php

declare(strict_types=1);

return [
    'database' => '{$databasePath}',
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

    private function bootKernel(): HttpKernel
    {
        $kernel = new HttpKernel($this->projectRoot);
        $boot = new \ReflectionMethod(AbstractKernel::class, 'boot');
        $boot->setAccessible(true);
        $boot->invoke($kernel);

        return $kernel;
    }
}
