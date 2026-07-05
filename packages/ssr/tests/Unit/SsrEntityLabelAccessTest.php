<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
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
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\SSR\SsrServiceProvider;

/**
 * R7 WP1 exploit-closed regression: the entity label/title field-access
 * channel.
 *
 * Before the fix, {@see SsrPageHandler} read {@see EntityInterface::label()}
 * directly at the HTML `<title>` block (`entity.html.twig`) and the
 * schema.org JSON-LD `name` ({@see \Waaseyaa\Seo\SchemaOrg\EntitySchemaOrgMapper}),
 * bypassing field-level access entirely. R6 PR2's entity-level view gate
 * ({@see SsrPageHandler::shouldDenyContentGroupRender()}) only closes the
 * FULLY-restricted case (entity-level Forbidden -> 403, nothing rendered). A
 * node that IS viewable at the entity level, but whose label-key field
 * ('title') is field-access-Forbidden, still rendered 200 with its real title
 * exposed in both the `<title>` tag and the JSON-LD `name` — the residual
 * this test closes.
 *
 * Drives {@see SsrPageHandler::handleRenderPage()} end to end against a REAL,
 * SQL-backed `node` entity through the REAL shipped `entity.html.twig`
 * template (via FilesystemLoader), mirroring {@see SsrNodeViewAccessGateTest}'s
 * storage wiring.
 */
#[CoversClass(SsrPageHandler::class)]
final class SsrEntityLabelAccessTest extends TestCase
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

        // The REAL shipped templates — this test exercises the actual
        // production `<title>` block and schema.org wiring, not a stub.
        SsrServiceProvider::setTwigEnvironment(new Environment(new FilesystemLoader(
            \dirname(__DIR__, 2) . '/templates',
        )));
    }

    protected function tearDown(): void
    {
        // Don't leak the static Twig environment into other test files.
        SsrServiceProvider::setTwigEnvironment(null);
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
     * Entity-level view is Allowed (the page renders 200, unlike the M2/PR2
     * fully-restricted case) but the label-key field ('title') is
     * field-access-Forbidden — the residual R7 WP1 closes.
     */
    private function forbidLabelFieldHandler(): EntityAccessHandler
    {
        return new EntityAccessHandler([
            new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return $operation === 'view' ? AccessResult::allowed('published, viewable') : AccessResult::neutral();
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
                    return $fieldName === 'title' && $operation === 'view'
                        ? AccessResult::forbidden('label field is classification-restricted')
                        : AccessResult::neutral();
                }
            },
        ]);
    }

    /**
     * Positive control: entity-level view Allowed AND the label field is
     * unrestricted (Neutral) — the real title must still render.
     */
    private function allowEverythingHandler(): EntityAccessHandler
    {
        return new EntityAccessHandler([
            new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return $operation === 'view' ? AccessResult::allowed('published, viewable') : AccessResult::neutral();
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
    public function html_title_does_not_expose_the_label_when_the_label_field_is_forbidden(): void
    {
        $entity = $this->repository->create(['title' => self::NODE_TITLE, 'status' => true]);
        $this->repository->save($entity, validate: false);

        $handler = $this->handler($this->forbidLabelFieldHandler());
        $path = '/node/' . $entity->id();

        $result = $handler->handleRenderPage($path, $this->anonWithAccessContent(), HttpRequest::create($path));

        self::assertSame(200, $result['status'], 'entity-level view is Allowed, so the page must still render');
        $html = (string) $result['content'];
        self::assertStringNotContainsString(self::NODE_TITLE, $html, 'the restricted label must never reach the <title> tag');
        self::assertStringContainsString('<title>node</title>', $html, 'a fail-closed placeholder (entity type id) must be used instead');
    }

    #[Test]
    public function schema_org_jsonld_does_not_expose_the_label_when_the_label_field_is_forbidden(): void
    {
        $entity = $this->repository->create(['title' => self::NODE_TITLE, 'status' => true]);
        $this->repository->save($entity, validate: false);

        $handler = $this->handler($this->forbidLabelFieldHandler());
        $path = '/node/' . $entity->id();

        $result = $handler->handleRenderPage($path, $this->anonWithAccessContent(), HttpRequest::create($path));

        self::assertSame(200, $result['status']);
        $html = (string) $result['content'];
        self::assertStringContainsString('application/ld+json', $html);
        self::assertStringNotContainsString(self::NODE_TITLE, $html, 'the restricted label must never reach the JSON-LD name');
        self::assertStringContainsString('"name":"node"', $html, 'the JSON-LD name must use the fail-closed placeholder');
    }

    #[Test]
    public function html_title_and_schema_org_still_show_the_real_label_when_it_is_not_restricted(): void
    {
        // CRITICAL REGRESSION GUARD: the fix must not over-deny the common
        // case — a genuinely public node with no label-field restriction must
        // still show its real title everywhere.
        $entity = $this->repository->create(['title' => self::NODE_TITLE, 'status' => true]);
        $this->repository->save($entity, validate: false);

        $handler = $this->handler($this->allowEverythingHandler());
        $path = '/node/' . $entity->id();

        $result = $handler->handleRenderPage($path, $this->anonWithAccessContent(), HttpRequest::create($path));

        self::assertSame(200, $result['status']);
        $html = (string) $result['content'];
        self::assertStringContainsString('<title>' . self::NODE_TITLE . '</title>', $html);
        self::assertStringContainsString('"name":"' . self::NODE_TITLE . '"', $html);
    }
}
