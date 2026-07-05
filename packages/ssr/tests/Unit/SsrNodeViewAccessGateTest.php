<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * Full-pipeline exploit-closed regression for audit M2 / R6 PR2.
 *
 * Before the fix, {@see SsrPageHandler::shouldDenyEntityRender()} (formerly
 * `shouldDenyContentGroupRender()`) opened with
 * `if ($entityTypeId === 'node' || ...) { return false; }`, so a `node`
 * NEVER ran the entity-level `accessHandler->check($entity, 'view', $account)`
 * gate on the SSR HTML render path — the only node gate was
 * {@see \Waaseyaa\Workflows\EditorialVisibilityResolver::canRender()}, which
 * allows any PUBLISHED node outright. A published node whose access policy
 * returns Forbidden for ('view', $account) — e.g. a classification hold or an
 * insufficient-clearance policy, mirrored here by an anonymous policy — still
 * rendered 200 to anonymous with its full content.
 *
 * This test drives {@see SsrPageHandler::handleRenderPage()} end to end
 * against a REAL, SQL-backed `node` entity (mirroring
 * {@see SsrPageHandlerUuidResolutionTest}'s storage wiring) so the fix is
 * verified at the same seam a real HTTP request hits, not just at the private
 * `shouldDenyEntityRender()` method (see {@see SsrContentPublishedGateTest}
 * for that unit-level coverage).
 */
#[CoversClass(SsrPageHandler::class)]
final class SsrNodeViewAccessGateTest extends TestCase
{
    private const string NODE_TITLE = 'Held Content — Water Is Life';

    private DBALDatabase $db;
    private EntityRepository $repository;
    private EntityTypeManager $etm;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: Node::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            group: 'content',
        );

        new SqlSchemaHandler($entityType, $this->db)->ensureTable();

        $dispatcher = new EventDispatcher();
        $db = $this->db;
        $resolver = new SingleConnectionResolver($this->db);
        $this->repository = new EntityRepository(
            $entityType,
            new SqlStorageDriver($resolver),
            $dispatcher,
            database: $db,
        );
        $repository = $this->repository;

        $this->etm = new EntityTypeManager(
            $dispatcher,
            null,
            static fn(string $_id, EntityTypeInterface $t): EntityRepository => $repository,
        );
        $this->etm->registerEntityType($entityType);

        // No template files needed on disk: 'entity.html.twig' reads the
        // entity label directly (the same channel {@see EntityRenderer}'s
        // docblock documents as bypassing the fields-bag filter), so a bare
        // ArrayLoader is sufficient — mirrors SsrEntityHtmlAccessTest's Twig
        // setup, just wired through the public seam via the documented
        // SsrServiceProvider::setTwigEnvironment() test hook.
        SsrServiceProvider::setTwigEnvironment(new Environment(new ArrayLoader([
            'entity.html.twig' => '<article><h1>{{ entity.label }}</h1></article>',
        ])));
    }

    protected function tearDown(): void
    {
        // Don't leak the static Twig environment into other test files.
        SsrServiceProvider::setTwigEnvironment(null);
    }

    private function anon(): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(false);
        $account->method('hasPermission')->willReturn(false);

        return $account;
    }

    private function anonWithAccessContent(): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(false);
        $account->method('hasPermission')->willReturnCallback(
            static fn(string $permission): bool => $permission === 'access content',
        );

        return $account;
    }

    /**
     * Mirrors ClassificationFieldAccessPolicy's entity-level Forbidden for a
     * legal-hold / insufficient-clearance node: applies to 'node', returns
     * Forbidden for 'view' regardless of account.
     */
    private function forbidNodeViewHandler(): EntityAccessHandler
    {
        return new EntityAccessHandler([
            new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return $operation === 'view'
                        ? AccessResult::forbidden('Entity is under legal hold.')
                        : AccessResult::neutral();
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'node';
                }

                public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }
            },
        ]);
    }

    private function handler(?EntityAccessHandler $accessHandler): SsrPageHandler
    {
        return new SsrPageHandler(
            entityTypeManager: $this->etm,
            database: $this->db,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver([]),
            discoveryHandler: new DiscoveryApiHandler($this->etm, $this->db),
            projectRoot: '/tmp/test-project',
            config: [],
            accessHandler: $accessHandler,
        );
    }

    #[Test]
    public function a_published_but_view_forbidden_node_is_denied_whole_page_to_anonymous(): void
    {
        $entity = $this->repository->create(['title' => self::NODE_TITLE, 'status' => true]);
        $this->repository->save($entity, validate: false);

        $handler = $this->handler($this->forbidNodeViewHandler());
        $path = '/node/' . $entity->id();

        $result = $handler->handleRenderPage($path, $this->anon(), HttpRequest::create($path));

        self::assertSame(403, $result['status'], 'a published node forbidden by entity-level access policy must be denied, not rendered');
        self::assertStringNotContainsString(self::NODE_TITLE, (string) $result['content'], 'the held node title/content must never reach the response');
    }

    #[Test]
    public function a_standard_published_node_still_renders_for_anonymous_with_access_content(): void
    {
        // CRITICAL REGRESSION GUARD: the fix must not over-deny the common
        // case — a genuinely public published node with the real
        // NodeAccessPolicy and an anonymous account holding `access content`
        // must still render 200.
        //
        // NOTE: this wires NodeAccessPolicy + an anonymous account that HOLDS
        // `access content`, which is NARROWER than production's real safety
        // net. In production the framework-default PublishedContentAccessPolicy
        // (`packages/access/src/Policy/PublishedContentAccessPolicy.php`,
        // wired for the whole `content` group at boot) grants anonymous 'view'
        // of any PUBLISHED content-group entity with NO permission required —
        // so a genuinely public node renders for a zero-permission anonymous
        // caller. `access content` is not a prerequisite for the public-node
        // guarantee; it is used here only to exercise the NodeAccessPolicy
        // grant branch in isolation.
        $entity = $this->repository->create(['title' => self::NODE_TITLE, 'status' => true]);
        $this->repository->save($entity, validate: false);

        $handler = $this->handler(new EntityAccessHandler([new NodeAccessPolicy()]));
        $path = '/node/' . $entity->id();

        $result = $handler->handleRenderPage($path, $this->anonWithAccessContent(), HttpRequest::create($path));

        self::assertSame(200, $result['status'], 'a standard published node must still render 200 for anonymous with access content permission');
        self::assertStringContainsString(self::NODE_TITLE, (string) $result['content']);
    }

    #[Test]
    public function a_standard_published_node_is_denied_to_anonymous_without_access_content(): void
    {
        // Sanity companion to the regression guard above: NodeAccessPolicy
        // itself denies view without the permission, proving the positive
        // case above is not a false pass from an overly-permissive handler.
        $entity = $this->repository->create(['title' => self::NODE_TITLE, 'status' => true]);
        $this->repository->save($entity, validate: false);

        $handler = $this->handler(new EntityAccessHandler([new NodeAccessPolicy()]));
        $path = '/node/' . $entity->id();

        $result = $handler->handleRenderPage($path, $this->anon(), HttpRequest::create($path));

        self::assertSame(403, $result['status']);
        self::assertStringNotContainsString(self::NODE_TITLE, (string) $result['content']);
    }
}
