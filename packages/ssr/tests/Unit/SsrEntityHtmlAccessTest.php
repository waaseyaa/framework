<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\SSR\ArrayViewModeConfig;
use Waaseyaa\SSR\EntityRenderer;
use Waaseyaa\SSR\FieldFormatterRegistry;
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\SSR\ViewMode;

/**
 * R6 PR1 exploit-closed regression (audit M1, api-M4 class re-opened on the
 * HTML entry).
 *
 * Before the fix, {@see SsrPageHandler} built the HTML branch via
 * `new RenderController($twig, $entityRenderer)->renderEntity($entity, $viewMode, $context)`
 * with no account and no field-level filtering anywhere in the call chain —
 * {@see \Waaseyaa\SSR\EntityRenderer::render()} took no $account parameter at
 * all. A field-access-forbidden field of a viewable, published entity leaked
 * into the rendered HTML (and was cached). `renderEntityHtml()` — the private
 * seam this test drives via Reflection, mirroring
 * {@see SsrEntityMarkdownAccessTest} — now threads the real $account and
 * `$this->accessHandler` through `EntityRenderer::render()`, and fails closed
 * with a 500 when no access handler is wired (mirroring
 * `renderEntityMarkdown()` exactly), instead of falling back to unfiltered
 * output.
 */
#[CoversClass(SsrPageHandler::class)]
final class SsrEntityHtmlAccessTest extends TestCase
{
    private function entityTypeManager(): EntityTypeManager
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(TestEntityType::stub(
            'article',
            [
                'title' => new FieldDefinition(name: 'title', type: 'string'),
                'secret_note' => new FieldDefinition(name: 'secret_note', type: 'string'),
            ],
            keys: TestEntity::definitionKeys(),
            class: TestEntity::class,
            label: 'Article',
        ));

        return $manager;
    }

    private function forbidSecretNoteHandler(): EntityAccessHandler
    {
        return new EntityAccessHandler([
            new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'article';
                }

                public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
                {
                    return $fieldName === 'secret_note' ? AccessResult::forbidden() : AccessResult::neutral();
                }
            },
        ]);
    }

    private function handler(?EntityAccessHandler $accessHandler, EntityTypeManager $entityTypeManager): SsrPageHandler
    {
        $database = DBALDatabase::createSqlite();

        return new SsrPageHandler(
            entityTypeManager: $entityTypeManager,
            database: $database,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver([]),
            discoveryHandler: new DiscoveryApiHandler($entityTypeManager, $database),
            projectRoot: '/tmp/test-project',
            config: [],
            accessHandler: $accessHandler,
        );
    }

    private function sampleEntity(): TestEntity
    {
        return new TestEntity(
            ['id' => 1, 'uuid' => 'u-1', 'type' => 'article', 'title' => 'Public Title', 'secret_note' => 'classified'],
            'article',
            TestEntity::definitionKeys(),
        );
    }

    /**
     * The same field-name-and-weight display the shipped `entity.html.twig`
     * (`{% for field_name, field in fields %}{{ field.formatted|raw }}`)
     * relies on, so this test drives the exact production loop shape.
     */
    private function viewModeConfig(): ArrayViewModeConfig
    {
        return new ArrayViewModeConfig([
            'article' => [
                'full' => [
                    'title' => ['formatter' => 'string', 'weight' => 0],
                    'secret_note' => ['formatter' => 'string', 'weight' => 1],
                ],
            ],
        ]);
    }

    /**
     * Invoke the private renderEntityHtml() the same way handleRenderPage()
     * does on the negotiated-HTML branch, rendering through the shipped
     * `entity.html.twig` field loop.
     */
    private function renderHtml(
        SsrPageHandler $handler,
        TestEntity $entity,
        AccountInterface $account,
        ?EntityAccessHandler $accessHandlerForRenderer,
        EntityTypeManager $manager,
    ): HttpResponse {
        $twig = new Environment(new ArrayLoader([
            'entity.html.twig' => '<article>{% for field_name, field in fields %}'
                . '<div class="field field-{{ field_name }}">{{ field.formatted|raw }}</div>'
                . '{% endfor %}</article>',
        ]));
        $entityRenderer = new EntityRenderer($manager, new FieldFormatterRegistry(), $this->viewModeConfig(), $accessHandlerForRenderer);

        $method = new \ReflectionMethod($handler, 'renderEntityHtml');

        return $method->invoke($handler, $entity, new ViewMode('full'), $entityRenderer, $twig, [], $account);
    }

    #[Test]
    public function html_omits_a_field_restricted_for_the_viewing_account(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $accessHandler = $this->forbidSecretNoteHandler();
        $handler = $this->handler($accessHandler, $this->entityTypeManager());

        $response = $this->renderHtml($handler, $this->sampleEntity(), $account, $accessHandler, $this->entityTypeManager());

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringNotContainsString('classified', $html);
        self::assertStringNotContainsString('field-secret_note', $html);
        self::assertStringContainsString('Public Title', $html);
    }

    #[Test]
    public function html_still_renders_every_field_for_anonymous_when_none_are_restricted(): void
    {
        // Positive regression: enforcing field access must not over-filter a
        // viewable entity with no restricted fields.
        $account = $this->createMock(AccountInterface::class);
        $accessHandler = new EntityAccessHandler([]);
        $handler = $this->handler($accessHandler, $this->entityTypeManager());

        $response = $this->renderHtml($handler, $this->sampleEntity(), $account, $accessHandler, $this->entityTypeManager());

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('Public Title', $html);
        self::assertStringContainsString('classified', $html);
    }

    #[Test]
    public function html_render_fails_closed_when_no_access_handler_is_wired(): void
    {
        // Defensive-only path: production always wires accessHandler via
        // SsrServiceProvider from the kernel's non-nullable
        // getAccessHandler(), so this branch is not reachable in production.
        // A direct/test construction of SsrPageHandler without one must
        // refuse to render rather than serve unfiltered content.
        $account = $this->createMock(AccountInterface::class);
        $handler = $this->handler(null, $this->entityTypeManager());

        $response = $this->renderHtml($handler, $this->sampleEntity(), $account, null, $this->entityTypeManager());

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('classified', (string) $response->getContent());
        self::assertStringNotContainsString('Public Title', (string) $response->getContent());
    }
}
