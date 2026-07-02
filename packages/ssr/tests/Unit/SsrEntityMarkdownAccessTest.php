<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
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
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\SSR\ViewMode;

/**
 * WP4 exploit-closed regression.
 *
 * Before the fix, {@see SsrPageHandler::renderEntityMarkdown()} called
 * {@see \Waaseyaa\Api\Markdown\EntityMarkdownPresenter::present()} with
 * accessHandler=null, account=null — the presenter's only production caller —
 * so the per-account field-access filter the presenter's docblock promised
 * was never applied to SSR Markdown output: a field a policy forbids for the
 * viewing account still rendered. present() now requires both parameters
 * non-nullable, and this handler threads the real $account and
 * $this->accessHandler through, so the restriction is enforced.
 *
 * renderEntityMarkdown() is invoked via Reflection because it is a private
 * seam of {@see SsrPageHandler} — the same call handleRenderPage() makes on
 * its negotiated-Markdown branch — without standing up the full Twig/cache
 * render pipeline handleRenderPage() also drives.
 */
#[CoversClass(SsrPageHandler::class)]
final class SsrEntityMarkdownAccessTest extends TestCase
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
     * Invoke the private renderEntityMarkdown() the same way
     * handleRenderPage() does on the negotiated-Markdown branch.
     */
    private function renderMarkdown(SsrPageHandler $handler, TestEntity $entity, AccountInterface $account): HttpResponse
    {
        $method = new \ReflectionMethod($handler, 'renderEntityMarkdown');

        return $method->invoke(
            $handler,
            $entity,
            new ViewMode('full'),
            new ArrayViewModeConfig([]),
            '/articles/x',
            $account,
        );
    }

    #[Test]
    public function markdown_omits_a_field_restricted_for_the_viewing_account(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $handler = $this->handler($this->forbidSecretNoteHandler(), $this->entityTypeManager());

        $response = $this->renderMarkdown($handler, $this->sampleEntity(), $account);

        self::assertSame(200, $response->getStatusCode());
        $markdown = (string) $response->getContent();
        self::assertStringNotContainsString('classified', $markdown);
        self::assertStringNotContainsString('## Secret Note', $markdown);
        self::assertStringContainsString('Public Title', $markdown);
    }

    #[Test]
    public function markdown_render_fails_closed_when_no_access_handler_is_wired(): void
    {
        // Defensive-only path: production always wires accessHandler via
        // SsrServiceProvider from the kernel's non-nullable
        // getAccessHandler(), so this branch is not reachable in production.
        // A direct/test construction of SsrPageHandler without one must
        // refuse to render rather than call the presenter with a bypass.
        $account = $this->createMock(AccountInterface::class);
        $handler = $this->handler(null, $this->entityTypeManager());

        $response = $this->renderMarkdown($handler, $this->sampleEntity(), $account);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('classified', (string) $response->getContent());
    }
}
