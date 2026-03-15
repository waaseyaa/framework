<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Cache\CacheConfigResolver;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\I18n\Language;
use Waaseyaa\I18n\LanguageManager;
use Waaseyaa\I18n\LanguageManagerInterface;
use Waaseyaa\SSR\SsrPageHandler;

#[CoversClass(SsrPageHandler::class)]
final class SsrPageHandlerTest extends TestCase
{
    private function createHandler(array $config = [], ?\Closure $serviceResolver = null): SsrPageHandler
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $database = PdoDatabase::createSqlite();
        $discoveryHandler = new DiscoveryApiHandler($entityTypeManager, $database);
        $cacheConfigResolver = new CacheConfigResolver($config);

        return new SsrPageHandler(
            entityTypeManager: $entityTypeManager,
            database: $database,
            renderCache: null,
            cacheConfigResolver: $cacheConfigResolver,
            discoveryHandler: $discoveryHandler,
            projectRoot: '/tmp/test-project',
            config: $config,
            serviceResolver: $serviceResolver,
        );
    }

    /**
     * Create a handler with an app-level LanguageManager injected via serviceResolver.
     * Returns [$handler, $manager] so tests can assert state on the shared manager.
     *
     * @param list<Language> $languages
     * @return array{SsrPageHandler, LanguageManager}
     */
    private function createHandlerWithManager(array $languages = []): array
    {
        if ($languages === []) {
            $languages = [
                new Language('en', 'English', isDefault: true),
                new Language('oj', 'Anishinaabemowin'),
            ];
        }

        $manager = new LanguageManager($languages);

        $serviceResolver = static function (string $className) use ($manager): ?object {
            if ($className === LanguageManagerInterface::class) {
                return $manager;
            }
            return null;
        };

        return [$this->createHandler(serviceResolver: $serviceResolver), $manager];
    }

    #[Test]
    public function is_preview_requested_detects_true_string(): void
    {
        $handler = $this->createHandler();
        $request = HttpRequest::create('/test', 'GET', ['preview' => 'true']);
        $this->assertTrue($handler->isPreviewRequested($request));
    }

    #[Test]
    public function is_preview_requested_detects_yes_string(): void
    {
        $handler = $this->createHandler();
        $request = HttpRequest::create('/test', 'GET', ['preview' => 'yes']);
        $this->assertTrue($handler->isPreviewRequested($request));
    }

    #[Test]
    public function is_preview_requested_detects_one_string(): void
    {
        $handler = $this->createHandler();
        $request = HttpRequest::create('/test', 'GET', ['preview' => '1']);
        $this->assertTrue($handler->isPreviewRequested($request));
    }

    #[Test]
    public function is_preview_requested_rejects_false_string(): void
    {
        $handler = $this->createHandler();
        $request = HttpRequest::create('/test', 'GET', ['preview' => 'false']);
        $this->assertFalse($handler->isPreviewRequested($request));
    }

    #[Test]
    public function is_preview_requested_returns_false_when_absent(): void
    {
        $handler = $this->createHandler();
        $request = HttpRequest::create('/test', 'GET');
        $this->assertFalse($handler->isPreviewRequested($request));
    }

    #[Test]
    public function build_language_manager_defaults_to_english(): void
    {
        $handler = $this->createHandler();
        $manager = $handler->buildLanguageManager();
        $languages = $manager->getLanguages();

        $this->assertArrayHasKey('en', $languages);
        $this->assertTrue($languages['en']->isDefault);
    }

    #[Test]
    public function build_language_manager_uses_configured_languages(): void
    {
        $handler = $this->createHandler([
            'i18n' => [
                'languages' => [
                    ['id' => 'fr', 'label' => 'French', 'is_default' => true],
                    ['id' => 'de', 'label' => 'German'],
                ],
            ],
        ]);
        $manager = $handler->buildLanguageManager();
        $languages = $manager->getLanguages();

        $this->assertArrayHasKey('fr', $languages);
        $this->assertArrayHasKey('de', $languages);
        $this->assertTrue($languages['fr']->isDefault);
        $this->assertFalse($languages['de']->isDefault);
    }

    #[Test]
    public function build_language_manager_assigns_default_when_none_configured(): void
    {
        $handler = $this->createHandler([
            'i18n' => [
                'languages' => [
                    ['id' => 'fr', 'label' => 'French'],
                    ['id' => 'de', 'label' => 'German'],
                ],
            ],
        ]);
        $manager = $handler->buildLanguageManager();
        $languages = $manager->getLanguages();

        $this->assertTrue($languages['fr']->isDefault);
    }

    #[Test]
    public function detect_language_prefix_from_path_finds_known_language(): void
    {
        $handler = $this->createHandler();
        $this->assertSame('fr', $handler->detectLanguagePrefixFromPath('/fr/about', ['en', 'fr', 'de']));
    }

    #[Test]
    public function detect_language_prefix_from_path_returns_null_for_unknown(): void
    {
        $handler = $this->createHandler();
        $this->assertNull($handler->detectLanguagePrefixFromPath('/about', ['en', 'fr']));
    }

    #[Test]
    public function strip_language_prefix_removes_prefix(): void
    {
        $handler = $this->createHandler();
        $this->assertSame('/about', $handler->stripLanguagePrefix('/fr/about', 'fr'));
    }

    #[Test]
    public function strip_language_prefix_returns_root_for_bare_language_path(): void
    {
        $handler = $this->createHandler();
        $this->assertSame('/', $handler->stripLanguagePrefix('/fr', 'fr'));
    }

    #[Test]
    public function strip_language_prefix_preserves_path_without_prefix(): void
    {
        $handler = $this->createHandler();
        $this->assertSame('/about', $handler->stripLanguagePrefix('/about', 'fr'));
    }

    #[Test]
    public function sanitize_cache_token_normalizes_to_lowercase(): void
    {
        $handler = $this->createHandler();
        $this->assertSame('published', $handler->sanitizeCacheToken('Published', 'unknown'));
    }

    #[Test]
    public function sanitize_cache_token_replaces_special_characters(): void
    {
        $handler = $this->createHandler();
        $this->assertSame('hello-world', $handler->sanitizeCacheToken('hello world!', 'unknown'));
    }

    #[Test]
    public function sanitize_cache_token_uses_fallback_for_empty(): void
    {
        $handler = $this->createHandler();
        $this->assertSame('fallback', $handler->sanitizeCacheToken('!!!', 'fallback'));
    }

    #[Test]
    public function ssr_cache_variant_langcode_is_deterministic(): void
    {
        $handler = $this->createHandler();
        $context = [
            'workflow_visibility' => ['state' => 'published'],
            'relationship_navigation' => [
                'contract' => ['surface' => 'ssr_relationship_navigation', 'version' => 'v1.0'],
                'entity' => ['counts' => ['inbound' => 2, 'outbound' => 1]],
            ],
        ];
        $variantA = $handler->buildSsrCacheVariantLangcode('en', 'full', false, $context);
        $variantB = $handler->buildSsrCacheVariantLangcode('en', 'full', false, $context);
        $this->assertSame($variantA, $variantB);
    }

    #[Test]
    public function ssr_cache_variant_langcode_changes_with_workflow_or_graph(): void
    {
        $handler = $this->createHandler();
        $published = $handler->buildSsrCacheVariantLangcode('en', 'full', false, [
            'workflow_visibility' => ['state' => 'published'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 1]]],
        ]);
        $review = $handler->buildSsrCacheVariantLangcode('en', 'full', false, [
            'workflow_visibility' => ['state' => 'review'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 1]]],
        ]);
        $differentGraph = $handler->buildSsrCacheVariantLangcode('en', 'full', false, [
            'workflow_visibility' => ['state' => 'published'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 3]]],
        ]);
        $previewVariant = $handler->buildSsrCacheVariantLangcode('en', 'full', true, [
            'workflow_visibility' => ['state' => 'published'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 1]]],
        ]);

        $this->assertNotSame($published, $review);
        $this->assertNotSame($published, $differentGraph);
        $this->assertNotSame($published, $previewVariant);
        $this->assertStringStartsWith('v2:en:full:public:published:', $published);
    }

    #[Test]
    public function render_surrogate_headers_include_workflow_and_graph_dimensions(): void
    {
        $handler = $this->createHandler();
        $headers = $handler->buildRenderSurrogateHeaders(
            'node', '42', 'full', 'en', 'v2:en:full:public:published:abc123',
            [
                'workflow_visibility' => ['state' => 'published'],
                'relationship_navigation' => [
                    'entity' => ['counts' => ['outbound' => 3]],
                ],
            ],
        );

        $this->assertArrayHasKey('Surrogate-Key', $headers);
        $this->assertArrayHasKey('X-Waaseyaa-Render-Variant', $headers);
        $this->assertArrayHasKey('X-Waaseyaa-Render-Workflow', $headers);
        $this->assertStringContainsString('waaseyaa:ssr', $headers['Surrogate-Key']);
        $this->assertStringContainsString('waaseyaa:ssr:entity:node', $headers['Surrogate-Key']);
        $this->assertStringContainsString('waaseyaa:ssr:entity:node:42', $headers['Surrogate-Key']);
        $this->assertStringContainsString('waaseyaa:ssr:workflow:published', $headers['Surrogate-Key']);
        $this->assertSame('published', $headers['X-Waaseyaa-Render-Workflow']);
    }

    #[Test]
    public function render_language_resolution_uses_url_prefix(): void
    {
        [$handler] = $this->createHandlerWithManager([
            new Language('en', 'English', isDefault: true),
            new Language('fr', 'French'),
        ]);
        $request = HttpRequest::create('/fr/about');
        $result = $handler->resolveRenderLanguageAndAliasPath('/fr/about', $request);

        $this->assertSame('fr', $result['langcode']);
        $this->assertSame('/about', $result['alias_path']);
    }

    #[Test]
    public function render_language_resolution_defaults_to_english(): void
    {
        [$handler] = $this->createHandlerWithManager();
        $request = HttpRequest::create('/about');
        $result = $handler->resolveRenderLanguageAndAliasPath('/about', $request);

        $this->assertSame('en', $result['langcode']);
        $this->assertSame('/about', $result['alias_path']);
    }

    // --- i18n refactor: unified LanguageManager tests ---

    #[Test]
    public function resolve_language_manager_throws_when_no_app_manager(): void
    {
        // Handler created without serviceResolver — no app-level LanguageManager available.
        $handler = $this->createHandler();
        $request = HttpRequest::create('/about');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LanguageManagerInterface not registered');

        $handler->resolveRenderLanguageAndAliasPath('/about', $request);
    }
}
