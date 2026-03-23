<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase11;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\User;

#[CoversNothing]
final class AuthorizationPipelineTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function (EntityType $def) use ($dispatcher): SqlEntityStorage {
                $schema = new SqlSchemaHandler($def, $this->database);
                $schema->ensureTable();
                return new SqlEntityStorage($def, $this->database, $dispatcher);
            },
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
    }

    #[Test]
    public function anonymous_is_denied_on_permission_protected_route(): void
    {
        $pipeline = $this->buildPipeline();
        $request = $this->buildRequest('/api/node', '_permission', 'access content');

        $response = $pipeline->handle($request, $this->successHandler());

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function authenticated_user_without_permission_is_denied(): void
    {
        $user = new User(['uid' => 1, 'name' => 'editor', 'permissions' => []]);
        $user->enforceIsNew();
        $this->entityTypeManager->getStorage('user')->save($user);

        $pipeline = $this->buildPipeline();
        $request = $this->buildRequest('/api/node', '_permission', 'access content');
        $request->attributes->set('_session', ['waaseyaa_uid' => 1]);

        $response = $pipeline->handle($request, $this->successHandler());

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function authenticated_user_with_permission_passes_through(): void
    {
        $user = new User(['uid' => 2, 'name' => 'admin', 'permissions' => ['access content']]);
        $user->enforceIsNew();
        $this->entityTypeManager->getStorage('user')->save($user);

        $pipeline = $this->buildPipeline();
        $request = $this->buildRequest('/api/node', '_permission', 'access content');
        $request->attributes->set('_session', ['waaseyaa_uid' => 2]);

        $response = $pipeline->handle($request, $this->successHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $response->getContent());
    }

    #[Test]
    public function public_route_allows_anonymous(): void
    {
        $pipeline = $this->buildPipeline();
        $request = $this->buildRequest('/public', '_public', true);

        $response = $pipeline->handle($request, $this->successHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function route_with_no_requirements_allows_anonymous(): void
    {
        $pipeline = $this->buildPipeline();

        $route = new Route('/open');
        $request = Request::create('/open');
        $request->attributes->set('_route_object', $route);

        $response = $pipeline->handle($request, $this->successHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    private function buildPipeline(): HttpPipeline
    {
        $userStorage = $this->entityTypeManager->getStorage('user');
        $accessChecker = new AccessChecker();

        return (new HttpPipeline())
            ->withMiddleware(new SessionMiddleware($userStorage))
            ->withMiddleware(new AuthorizationMiddleware($accessChecker));
    }

    private function buildRequest(string $path, string $optionKey, mixed $optionValue): Request
    {
        $route = new Route($path);
        $route->setOption($optionKey, $optionValue);

        $request = Request::create($path);
        $request->attributes->set('_route_object', $route);

        return $request;
    }

    private function successHandler(): HttpHandlerInterface
    {
        return new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('success', 200);
            }
        };
    }
}
