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
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Policy\PublishedContentAccessPolicy;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Path\PathAlias;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\SSR\SsrServiceProvider;
use Waaseyaa\Taxonomy\TermAccessPolicy;

/**
 * R8-a exploit-closed regression: {@see SsrPageHandler::shouldDenyEntityRender()}
 * (formerly `shouldDenyContentGroupRender()`) used to open with an
 * `isContentGroupEntity()` early-return, so any entity type OUTSIDE the
 * `content` group skipped the entity-level `view` access check entirely on
 * the SSR render path. {@see \Waaseyaa\Workflows\EditorialVisibilityResolver}
 * allows any non-`node` type outright, so a `path_alias` pointing at a
 * non-content-group type whose `AccessPolicy` returns Neutral / deny-by-default
 * (mirrored here after {@see \Waaseyaa\Oidc\Access\OidcClientAccessPolicy}) was
 * disclosed WHOLE-ENTITY to anonymous with no gate at all.
 *
 * Drives {@see SsrPageHandler::handleRenderPage()} end to end against REAL,
 * SQL-backed entities and a REAL {@see \Waaseyaa\Path\PathAliasResolver}
 * resolution (mirroring {@see SsrNodeViewAccessGateTest}'s storage wiring), so
 * the fix is verified at the same seam a real HTTP request hits.
 *
 * Also carries the required regression guard (Step 3 of the R8-a remediation):
 * a genuinely public PUBLISHED entity of a non-`node` `content`-group type must
 * still render 200 for anonymous via the boot-wired
 * {@see PublishedContentAccessPolicy} — the primary-entity gate must not
 * over-deny the common case while closing the non-content-group hole.
 */
#[CoversClass(SsrPageHandler::class)]
final class SsrEntityViewGateAllTypesTest extends TestCase
{
    private const string WIDGET_TITLE = 'Top Secret Widget Payload';
    private const string STORY_TITLE = 'A Genuinely Public Story';
    private const string TERM_TITLE = 'Anishinaabemowin';

    private DBALDatabase $db;
    private EntityTypeManager $etm;

    /** @var array<string, EntityRepository> */
    private array $repositories = [];

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();

        $this->etm = new EntityTypeManager(
            $dispatcher,
            null,
            fn(string $id): EntityRepository => $this->repositories[$id],
        );

        // A non-content-group entity type reachable only via a hand-created
        // alias — mirrors `oidc_client`'s shape (admin-only, deny-by-default
        // for anonymous). The `group` is deliberately NOT 'content'.
        $this->registerEntityType(new EntityType(
            id: 'restricted_widget',
            label: 'Restricted Widget',
            class: GenericSsrTermEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            group: 'widgets',
        ));

        // A non-node `content`-group type — the positive control for the
        // "content group keeps working" regression guard.
        $this->registerEntityType(new EntityType(
            id: 'story',
            label: 'Story',
            class: GenericSsrContentEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            group: 'content',
        ));

        // The REAL `taxonomy_term` type (group 'taxonomy') so the intentional
        // R8-a behavior change can be pinned against the REAL TermAccessPolicy.
        // Node backs the storage schema (status + label columns), which is all
        // TermAccessPolicy reads — it never depends on the Term class itself.
        $this->registerEntityType(new EntityType(
            id: 'taxonomy_term',
            label: 'Taxonomy term',
            class: GenericSsrTermEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            group: 'taxonomy',
        ));

        $this->registerEntityType(EntityType::fromClass(PathAlias::class, group: 'structure'));

        SsrServiceProvider::setTwigEnvironment(new Environment(new ArrayLoader([
            'entity.html.twig' => '<article><h1>{{ entity.label }}</h1></article>',
        ])));
    }

    protected function tearDown(): void
    {
        SsrServiceProvider::setTwigEnvironment(null);
    }

    private function registerEntityType(EntityTypeInterface $entityType): void
    {
        new SqlSchemaHandler($entityType, $this->db)->ensureTable();
        $resolver = new SingleConnectionResolver($this->db);
        $this->repositories[$entityType->id()] = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver),
            new EventDispatcher(),
            database: $this->db,
        );
        $this->etm->registerEntityType($entityType);
    }

    private function anon(): AccountInterface
    {
        $account = $this->createMock(AuthorizationPrincipalInterface::class);
        $account->method('isAuthenticated')->willReturn(false);
        $account->method('hasPermission')->willReturn(false);

        return $account;
    }

    /**
     * Mirrors OidcClientAccessPolicy's shape: admin-only, Neutral (deny by
     * default) for every other account/operation.
     */
    private function denyByDefaultWidgetHandler(): EntityAccessHandler
    {
        $handler = new EntityAccessHandler([
            new class implements AccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    if ($account->hasPermission('administer widgets')) {
                        return AccessResult::allowed('User has "administer widgets" permission.');
                    }

                    return AccessResult::neutral('Widgets are admin-only.');
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'restricted_widget';
                }
            },
        ]);
        $handler->addPolicy(new PublishedContentAccessPolicy($this->etm));

        return $handler;
    }

    /**
     * Production-shaped boot handler: the framework-default
     * {@see PublishedContentAccessPolicy} (content group only) PLUS the REAL
     * {@see TermAccessPolicy} — exactly the pair a live install wires. Neither
     * grants a bare anonymous account `view` of a `taxonomy_term`
     * (PublishedContentAccessPolicy scopes to the `content` group; TermAccessPolicy
     * requires `access content`), so the generalized gate denies it.
     */
    private function realTermHandler(): EntityAccessHandler
    {
        $handler = new EntityAccessHandler([new TermAccessPolicy()]);
        $handler->addPolicy(new PublishedContentAccessPolicy($this->etm));

        return $handler;
    }

    private function handler(EntityAccessHandler $accessHandler): SsrPageHandler
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
    public function an_alias_to_a_deny_by_default_non_content_group_entity_is_denied_to_anonymous(): void
    {
        $widget = $this->repositories['restricted_widget']->create(['title' => self::WIDGET_TITLE, 'status' => true]);
        $this->repositories['restricted_widget']->save($widget, validate: false);

        $alias = $this->repositories['path_alias']->create([
            'alias' => '/my-secret-widget',
            'path' => '/restricted_widget/' . $widget->id(),
            'langcode' => 'en',
            'status' => true,
        ]);
        $this->repositories['path_alias']->save($alias, validate: false);

        $handler = $this->handler($this->denyByDefaultWidgetHandler());
        $path = '/my-secret-widget';

        $result = $handler->handleRenderPage($path, $this->anon(), HttpRequest::create($path));

        self::assertSame(
            403,
            $result['status'],
            'a non-content-group entity with a deny-by-default access policy must be denied via an alias, not rendered whole-entity',
        );
        self::assertStringNotContainsString(
            self::WIDGET_TITLE,
            (string) $result['content'],
            'the restricted widget content must never reach the response',
        );
    }

    #[Test]
    public function a_published_non_node_content_group_entity_still_renders_for_anonymous(): void
    {
        // REGRESSION GUARD (Step 3): the generalized gate must not over-deny
        // the common case for a non-`node` `content`-group type — the same
        // boot-wired PublishedContentAccessPolicy that already covers nodes
        // covers every content-group type.
        $story = $this->repositories['story']->create(['title' => self::STORY_TITLE, 'status' => true]);
        $this->repositories['story']->save($story, validate: false);

        $handler = $this->handler($this->denyByDefaultWidgetHandler());
        $path = '/story/' . $story->id();

        $result = $handler->handleRenderPage($path, $this->anon(), HttpRequest::create($path));

        self::assertSame(200, $result['status'], 'a published non-node content-group entity must still render 200 for anonymous');
        self::assertStringContainsString(self::STORY_TITLE, (string) $result['content']);
    }

    #[Test]
    public function a_published_taxonomy_term_is_denied_to_a_bare_anonymous_account(): void
    {
        // INTENTIONAL BEHAVIOR CHANGE (R8-a, Option A — enforce the access
        // model): SSR now applies the entity-level view check to ALL entity
        // types, so anonymous SSR of a `taxonomy_term` now matches what /api
        // already enforces — a bare anonymous account (zero permissions) is
        // DENIED. The framework-default PublishedContentAccessPolicy exposes
        // ONLY the `content` group; the REAL TermAccessPolicy grants `view`
        // only to an account holding `access content`, which a bare anonymous
        // account does not hold. A site that wants public anonymous term pages
        // must add a granting AccessPolicy for `taxonomy_term` (same requirement
        // as /api) — deferred as R9, a product decision, not a bug.
        //
        // The account here is genuinely BARE: hasPermission() returns false for
        // everything, `access content` explicitly NOT pre-granted.
        $term = $this->repositories['taxonomy_term']->create(['title' => self::TERM_TITLE, 'status' => true]);
        $this->repositories['taxonomy_term']->save($term, validate: false);

        $alias = $this->repositories['path_alias']->create([
            'alias' => '/tag/anishinaabemowin',
            'path' => '/taxonomy_term/' . $term->id(),
            'langcode' => 'en',
            'status' => true,
        ]);
        $this->repositories['path_alias']->save($alias, validate: false);

        $handler = $this->handler($this->realTermHandler());
        $path = '/tag/anishinaabemowin';

        $result = $handler->handleRenderPage($path, $this->anon(), HttpRequest::create($path));

        self::assertSame(
            403,
            $result['status'],
            'a published taxonomy_term must be denied to a bare anonymous account — SSR now matches /api (intentional R8-a change; public term pages require a site-provided granting policy, R9)',
        );
        self::assertStringNotContainsString(
            self::TERM_TITLE,
            (string) $result['content'],
            'the term label must not reach the response for a bare anonymous account',
        );
    }

    #[Test]
    public function a_published_content_group_entity_still_renders_for_a_bare_anonymous_account_via_the_real_policy(): void
    {
        // POSITIVE CONTROL for the intentional change above: the SAME
        // production-shaped handler (real PublishedContentAccessPolicy + real
        // TermAccessPolicy) + the SAME bare anonymous account STILL renders a
        // published `content`-group entity 200 — proving the R8-a change denies
        // ONLY the types that lack a permission-free published-view grant, and
        // does not regress the content group.
        $story = $this->repositories['story']->create(['title' => self::STORY_TITLE, 'status' => true]);
        $this->repositories['story']->save($story, validate: false);

        $handler = $this->handler($this->realTermHandler());
        $path = '/story/' . $story->id();

        $result = $handler->handleRenderPage($path, $this->anon(), HttpRequest::create($path));

        self::assertSame(200, $result['status'], 'a published content-group entity must still render 200 for a bare anonymous account via the real PublishedContentAccessPolicy');
        self::assertStringContainsString(self::STORY_TITLE, (string) $result['content']);
    }
}

/** Explicit public fields for the generic non-Node SSR surface fixture. */
final class GenericSsrContentEntity extends ContentEntityBase
{
    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(type: 'boolean', required: false, settings: ['authorizationInput' => true], read: FieldReadLevel::Protected)]
    public bool $status = false;
}

/** Taxonomy fixture keeps status public because the legacy TermAccessPolicy reads it directly. */
final class GenericSsrTermEntity extends ContentEntityBase
{
    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(type: 'boolean', required: false, read: FieldReadLevel::Public)]
    public bool $status = false;
}
