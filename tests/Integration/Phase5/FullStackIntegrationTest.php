<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase5;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\PermissionHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Queue\InMemoryQueue;
use Waaseyaa\Queue\Message\EntityMessage;
use Waaseyaa\Routing\ParamConverter\EntityParamConverter;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\State\MemoryState;
use Waaseyaa\Tests\Support\AuthorizationPrincipalFactory;
use Waaseyaa\Tests\Support\ClosedEntityValidatorFactory;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\User;
use Waaseyaa\User\UserSession;
use Waaseyaa\Validation\Constraint\AllowedValues;
use Waaseyaa\Validation\Constraint\NotEmpty;
use Waaseyaa\Validation\Constraint\SafeMarkup;

/**
 * Full-stack integration test spanning most Phase 5 packages.
 *
 * Exercises: waaseyaa/entity, waaseyaa/entity-storage, waaseyaa/database-legacy,
 * waaseyaa/user, waaseyaa/access, waaseyaa/routing, waaseyaa/queue, waaseyaa/state,
 * waaseyaa/validation working together end-to-end.
 */
#[CoversNothing]
final class FullStackIntegrationTest extends TestCase
{
    private DBALDatabase $database;
    private EventDispatcher $eventDispatcher;
    private EntityTypeManager $entityTypeManager;
    private EntityRepository $articleStorage;
    private WaaseyaaRouter $router;
    private AccessChecker $accessChecker;
    private EntityAccessHandler $entityAccessHandler;
    private EntityParamConverter $paramConverter;
    private PermissionHandler $permissionHandler;
    private MemoryState $state;
    private InMemoryQueue $auditQueue;

    protected function setUp(): void
    {
        // ---- Database layer ----
        $this->database = DBALDatabase::createSqlite();
        $this->eventDispatcher = new EventDispatcher();

        // ---- Entity type for "article" ----
        $articleType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestFullStackArticle::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'title',
                'langcode' => 'langcode',
            ],
            _fieldDefinitions: [
                'id' => ['type' => 'integer', 'read' => FieldReadLevel::Public],
                'uuid' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'bundle' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'title' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'langcode' => ['type' => 'string', 'read' => FieldReadLevel::Public],
                'body' => ['type' => 'text', 'read' => FieldReadLevel::Public],
                'status' => ['type' => 'integer', 'read' => FieldReadLevel::Public],
            ],
        );

        // Create the database table.
        $schemaHandler = new SqlSchemaHandler($articleType, $this->database);
        $schemaHandler->ensureTable();
        $schemaHandler->addFieldColumns([
            'body' => ['type' => 'text', 'not null' => false],
            'status' => ['type' => 'int', 'not null' => true, 'default' => 1],
        ]);

        // ---- Storage ----
        // C-22 WP4: legacy SqlEntityStorage engine is deleted; persistence now
        // goes through the canonical EntityRepository.
        $database = $this->database;
        $dispatcher = $this->eventDispatcher;
        $idKey = $articleType->getKeys()['id'] ?? 'id';
        $resolver = new SingleConnectionResolver($this->database);
        $this->articleStorage = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $articleType,
            new SqlStorageDriver($resolver, $idKey),
            $this->eventDispatcher,
            database: $this->database,
        );

        // ---- Entity Type Manager ----
        $articleRepositoryRef = $this->articleStorage;
        $this->entityTypeManager = new EntityTypeManager(
            $this->eventDispatcher,
            null,
            function (string $entityTypeId, $definition) use ($articleRepositoryRef): EntityRepository {
                if ($entityTypeId !== 'article') {
                    throw new \RuntimeException("Unknown entity type: {$entityTypeId}");
                }

                return $articleRepositoryRef;
            },
        );
        $this->entityTypeManager->registerEntityType($articleType);

        // ---- Permissions ----
        $this->permissionHandler = new PermissionHandler();
        $this->permissionHandler->registerPermission('access content', 'Access content');
        $this->permissionHandler->registerPermission('edit articles', 'Edit articles');
        $this->permissionHandler->registerPermission('delete articles', 'Delete articles');
        $this->permissionHandler->registerPermission('administer content', 'Administer content');

        // ---- Entity Access ----
        $this->entityAccessHandler = new EntityAccessHandler([
            new ArticleAccessPolicy(),
        ]);

        // ---- Routing ----
        $this->router = new WaaseyaaRouter();
        $this->accessChecker = new AccessChecker();
        $this->paramConverter = new EntityParamConverter($this->entityTypeManager);

        $this->router->addRoute(
            'article.view',
            RouteBuilder::create('/article/{article}')
                ->controller('ArticleController::view')
                ->entityParameter('article', 'article')
                ->requirePermission('access content')
                ->requirement('article', '\d+')
                ->methods('GET')
                ->build(),
        );

        $this->router->addRoute(
            'article.edit',
            RouteBuilder::create('/article/{article}/edit')
                ->controller('ArticleController::edit')
                ->entityParameter('article', 'article')
                ->requirePermission('edit articles')
                ->requirement('article', '\d+')
                ->methods('GET', 'POST')
                ->build(),
        );

        $this->router->addRoute(
            'article.list',
            RouteBuilder::create('/articles')
                ->controller('ArticleController::list')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // ---- State & Queue ----
        $this->state = new MemoryState();
        $this->auditQueue = new InMemoryQueue();
    }

    // ---- Full flow: create entity, match route, convert params, check access ----

    public function testCompleteFlowForAuthenticatedUser(): void
    {
        // Step 1: Create and persist an article.
        $article = $this->articleStorage->create([
            'title' => 'Integration Test Article',
            'bundle' => 'blog',
            'body' => 'This is a test article.',
            'status' => 1,
        ]);
        $this->articleStorage->save($article, validate: false);
        $articleId = $article->id();
        $this->assertNotNull($articleId);

        // Step 2: Create an authenticated user with content viewing permission.
        $user = AuthorizationPrincipalFactory::authenticated(
            permissions: ['access content'],
            accountId: 10,
        );

        // Step 3: Match the route.
        $params = $this->router->match("/article/{$articleId}");
        $this->assertSame('article.view', $params['_route']);
        $this->assertSame((string) $articleId, $params['article']);

        // Step 4: Convert the entity parameter.
        $route = $this->router->getRouteCollection()->get('article.view');
        $this->assertNotNull($route);
        $convertedParams = $this->paramConverter->convert($params, $route);
        $this->assertInstanceOf(EntityInterface::class, $convertedParams['article']);
        $this->assertSame('Integration Test Article', $convertedParams['article']->label());

        // Step 5: Check route access.
        $routeAccess = $this->accessChecker->check($route, $user);
        $this->assertTrue($routeAccess->isAllowed(), 'User with "access content" should access article.view.');

        // Step 6: Check entity access.
        $entityAccess = $this->entityAccessHandler->check($convertedParams['article'], 'view', $user);
        $this->assertTrue($entityAccess->isAllowed(), 'User with "access content" should view the article.');
    }

    public function testCompleteFlowDeniesAnonymousForEditRoute(): void
    {
        // Create an article.
        $article = $this->articleStorage->create([
            'title' => 'Protected Article',
            'bundle' => 'blog',
            'status' => 1,
        ]);
        $this->articleStorage->save($article, validate: false);

        $anonymous = new AnonymousUser();

        // Match the edit route.
        $params = $this->router->match("/article/{$article->id()}/edit");
        $this->assertSame('article.edit', $params['_route']);

        // Check route access.
        $route = $this->router->getRouteCollection()->get('article.edit');
        $routeAccess = $this->accessChecker->check($route, $anonymous);
        $this->assertTrue($routeAccess->isForbidden(), 'Anonymous should not access article.edit.');
    }

    public function testPublicListRouteAllowsAnonymous(): void
    {
        $anonymous = new AnonymousUser();

        $params = $this->router->match('/articles');
        $this->assertSame('article.list', $params['_route']);

        $route = $this->router->getRouteCollection()->get('article.list');
        $routeAccess = $this->accessChecker->check($route, $anonymous);
        $this->assertTrue($routeAccess->isAllowed(), 'Anonymous should access public article list.');
    }

    // ---- Entity CRUD + validation ----

    public function testEntityValidationBeforeSave(): void
    {
        $validator = Validation::createValidatorBuilder()->getValidator();
        $entityValidator = ClosedEntityValidatorFactory::create($validator);

        // Create an article with invalid data.
        $article = $this->articleStorage->create([
            'title' => '',
            'bundle' => 'blog',
            'body' => '<script>alert("xss")</script>',
            'status' => 99,
        ]);

        $violations = $entityValidator->validate($article, [
            'title' => [new NotEmpty()],
            'body' => [new SafeMarkup()],
            'status' => [new AllowedValues(values: [0, 1])],
        ]);

        $this->assertCount(3, $violations, 'Should have 3 violations: empty title, unsafe body, invalid status.');

        $paths = [];
        for ($i = 0; $i < $violations->count(); $i++) {
            $paths[] = $violations->get($i)->getPropertyPath();
        }
        $this->assertContains('title', $paths);
        $this->assertContains('body', $paths);
        $this->assertContains('status', $paths);
    }

    public function testValidEntityPassesValidationAndSaves(): void
    {
        $validator = Validation::createValidatorBuilder()->getValidator();
        $entityValidator = ClosedEntityValidatorFactory::create($validator);

        $article = $this->articleStorage->create([
            'title' => 'Valid Article',
            'bundle' => 'blog',
            'body' => 'Clean content without dangerous markup.',
            'status' => 1,
        ]);

        $violations = $entityValidator->validate($article, [
            'title' => [new NotEmpty()],
            'body' => [new SafeMarkup()],
            'status' => [new AllowedValues(values: [0, 1])],
        ]);

        $this->assertCount(0, $violations, 'Valid article should have no violations.');

        // Now save and verify persistence.
        $this->articleStorage->save($article, validate: false);
        $loaded = $this->articleStorage->find((string) $article->id());
        $this->assertNotNull($loaded);
        $this->assertSame('Valid Article', $loaded->label());
    }

    // ---- State tracking across operations ----

    public function testStateTracksEntityOperations(): void
    {
        // Create an article.
        $article = $this->articleStorage->create([
            'title' => 'Stateful Article',
            'bundle' => 'blog',
        ]);
        $this->articleStorage->save($article, validate: false);

        // Track the creation in state.
        $this->state->set('last_created_article_id', $article->id());
        $this->state->set('articles_created_count', 1);

        // Dispatch an audit message to the queue.
        $this->auditQueue->dispatch(new EntityMessage(
            'article',
            $article->id(),
            'created',
            ['title' => 'Stateful Article'],
        ));

        // Verify state.
        $this->assertSame($article->id(), $this->state->get('last_created_article_id'));
        $this->assertSame(1, $this->state->get('articles_created_count'));

        // Verify audit queue.
        $messages = $this->auditQueue->getMessages();
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(EntityMessage::class, $messages[0]);
        $this->assertSame('article', $messages[0]->entityTypeId);
        $this->assertSame($article->id(), $messages[0]->entityId);
        $this->assertSame('created', $messages[0]->operation);
    }

    // ---- UserSession through the stack ----

    public function testUserSessionIntegrationWithAccessChecking(): void
    {
        // Start with anonymous session.
        $session = new UserSession();
        $this->assertFalse($session->isAuthenticated());

        // Check access as anonymous for a protected route.
        $route = $this->router->getRouteCollection()->get('article.view');
        $result = $this->accessChecker->check($route, $session->getAccount());
        $this->assertTrue($result->isForbidden(), 'Anonymous session should be forbidden from article.view.');

        // Elevate to authenticated user.
        $user = AuthorizationPrincipalFactory::authenticated(
            permissions: ['access content', 'edit articles'],
            roles: ['administrator'],
        );
        $session->setAccount($user);

        // Now check access again.
        $result = $this->accessChecker->check($route, $session->getAccount());
        $this->assertTrue($result->isAllowed(), 'Authenticated session with permission should access article.view.');
    }

    // ---- Param converter error handling ----

    public function testParamConverterThrowsForNonexistentEntity(): void
    {
        $route = $this->router->getRouteCollection()->get('article.view');
        $params = ['_route' => 'article.view', 'article' => 99999];

        $this->expectException(\Symfony\Component\Routing\Exception\ResourceNotFoundException::class);
        $this->paramConverter->convert($params, $route);
    }

    // ---- URL generation from entity ----

    public function testUrlGenerationForExistingEntity(): void
    {
        $article = $this->articleStorage->create([
            'title' => 'URL Test',
            'bundle' => 'blog',
        ]);
        $this->articleStorage->save($article, validate: false);

        $url = $this->router->generate('article.view', ['article' => $article->id()]);
        $this->assertSame("/article/{$article->id()}", $url);
    }

    // ---- Multiple entities and list route ----

    public function testMultipleEntitiesWithListRoute(): void
    {
        $titles = ['First Article', 'Second Article', 'Third Article'];
        $ids = [];

        foreach ($titles as $title) {
            $article = $this->articleStorage->create([
                'title' => $title,
                'bundle' => 'blog',
                'status' => 1,
            ]);
            $this->articleStorage->save($article, validate: false);
            $ids[] = $article->id();
        }

        // All 3 articles exist.
        $loaded = array_values($this->articleStorage->findMany($ids));
        $this->assertCount(3, $loaded);

        // The list route is publicly accessible.
        $route = $this->router->getRouteCollection()->get('article.list');
        $anonymous = new AnonymousUser();
        $this->assertTrue(
            $this->accessChecker->check($route, $anonymous)->isAllowed(),
            'Article list should be publicly accessible.',
        );

        // But individual article viewing requires permission.
        $viewRoute = $this->router->getRouteCollection()->get('article.view');
        $this->assertTrue(
            $this->accessChecker->check($viewRoute, $anonymous)->isForbidden(),
            'Individual articles should require access content permission.',
        );
    }

    // ---- Complete entity lifecycle with audit trail ----

    public function testEntityLifecycleWithAuditTrail(): void
    {
        $this->state->set('audit.total', 0);

        // CREATE.
        $article = $this->articleStorage->create([
            'title' => 'Lifecycle Test',
            'bundle' => 'blog',
            'body' => 'Original body.',
            'status' => 1,
        ]);
        $this->articleStorage->save($article, validate: false);
        $this->auditQueue->dispatch(new EntityMessage('article', $article->id(), 'created'));
        $this->state->set('audit.total', $this->state->get('audit.total', 0) + 1);

        // UPDATE.
        $article->set('title', 'Updated Lifecycle Test');
        $article->set('body', 'Updated body.');
        $this->articleStorage->save($article, validate: false);
        $this->auditQueue->dispatch(new EntityMessage('article', $article->id(), 'updated'));
        $this->state->set('audit.total', $this->state->get('audit.total', 0) + 1);

        // Verify updated entity.
        $loaded = $this->articleStorage->find((string) $article->id());
        $this->assertSame('Updated Lifecycle Test', $loaded->label());
        $this->assertSame('Updated body.', $loaded->get('body'));

        // DELETE.
        $id = $article->id();
        $this->articleStorage->delete($article);
        $this->auditQueue->dispatch(new EntityMessage('article', $id, 'deleted'));
        $this->state->set('audit.total', $this->state->get('audit.total', 0) + 1);

        // Verify entity is gone.
        $this->assertNull($this->articleStorage->find((string) $id));

        // Verify audit trail.
        $this->assertSame(3, $this->state->get('audit.total'));
        $auditMessages = $this->auditQueue->getMessages();
        $this->assertCount(3, $auditMessages);
        $this->assertSame('created', $auditMessages[0]->operation);
        $this->assertSame('updated', $auditMessages[1]->operation);
        $this->assertSame('deleted', $auditMessages[2]->operation);
    }
}

// ---- Supporting classes ----

/**
 * Concrete article entity for full-stack integration tests.
 */
#[ContentEntityType(id: 'article')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', bundle: 'bundle', langcode: 'langcode')]
class TestFullStackArticle extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = 'article',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}

/**
 * Access policy for article entities based on permissions.
 */
class ArticleAccessPolicy implements AccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return match ($operation) {
            'view' => $account->hasPermission('access content')
                ? AccessResult::allowed()
                : AccessResult::neutral('Missing "access content" permission.'),
            'update' => $account->hasPermission('edit articles')
                ? AccessResult::allowed()
                : AccessResult::neutral('Missing "edit articles" permission.'),
            'delete' => $account->hasPermission('delete articles')
                ? AccessResult::allowed()
                : AccessResult::neutral('Missing "delete articles" permission.'),
            default => AccessResult::neutral("Unknown operation: {$operation}"),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return $account->hasPermission('administer content')
            ? AccessResult::allowed()
            : AccessResult::neutral('Missing "administer content" permission.');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'article';
    }
}
