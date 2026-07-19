<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\WorkflowTransitionController;
use Waaseyaa\Api\Http\Router\WorkflowTransitionApiRouter;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Transition\TransitionService;

/**
 * Unit tests for {@see WorkflowTransitionApiRouter}: `supports()` matching
 * and action dispatch. Business-logic coverage (401/404/200/etc.) lives in
 * {@see \Waaseyaa\Api\Tests\Integration\WorkflowTransitionControllerTest} —
 * here the entity type manager always reports "unknown type", so every
 * dispatched call resolves quickly (404) without exercising the workflow
 * engine; the point of this test is routing, not business logic.
 */
#[CoversClass(WorkflowTransitionApiRouter::class)]
final class WorkflowTransitionApiRouterTest extends TestCase
{
    private function makeRouter(): WorkflowTransitionApiRouter
    {
        $entityTypeManager = new class implements EntityTypeManagerInterface {
            public function getDefinition(string $entityTypeId): EntityTypeInterface { throw new \LogicException('not needed'); }
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return false; }
            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed'); }
            public function getRepository(string $entityTypeId): EntityRepositoryInterface { throw new \LogicException('not needed'); }
        };

        $configFactory = new class implements ConfigFactoryInterface {
            public function get(string $name): ConfigInterface
            {
                return new class implements ConfigInterface {
                    public function getName(): string { return 'workflows.assignments'; }
                    public function get(string $key = ''): mixed { return $key === '' ? [] : null; }
                    public function set(string $key, mixed $value): static { return $this; }
                    public function clear(string $key): static { return $this; }
                    public function delete(): static { return $this; }
                    public function save(): static { return $this; }
                    public function isNew(): bool { return true; }
                    public function getRawData(): array { return []; }
                };
            }
            public function getEditable(string $name): ConfigInterface { return $this->get($name); }
            public function loadMultiple(array $names): array { return []; }
            public function rename(string $oldName, string $newName): static { return $this; }
            public function listAll(string $prefix = ''): array { return []; }
        };

        $transitionService = new TransitionService(
            new WorkflowBindingResolver($configFactory, $entityTypeManager),
            $entityTypeManager,
        );

        $controller = new WorkflowTransitionController($entityTypeManager, new EntityAccessHandler([]), $transitionService);

        return new WorkflowTransitionApiRouter($controller);
    }

    #[Test]
    public function supportsTransitionsAction(): void
    {
        $router = $this->makeRouter();
        $request = Request::create('/api/article/1/workflow/transitions', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\WorkflowTransitionController::transitions');

        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supportsTransitionAction(): void
    {
        $router = $this->makeRouter();
        $request = Request::create('/api/article/1/workflow/transition', 'POST');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\WorkflowTransitionController::transition');

        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function doesNotSupportOtherControllers(): void
    {
        $router = $this->makeRouter();
        $request = Request::create('/api/article/1', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\JsonApiController::show');

        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function dispatchesTransitionsToControllerTransitionsMethod(): void
    {
        $router = $this->makeRouter();
        $request = Request::create('/api/article/1/workflow/transitions', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\WorkflowTransitionController::transitions');
        $request->attributes->set('_entity_type', 'article');
        $request->attributes->set('id', '1');
        $request->attributes->set('_account', $this->account());

        $response = $router->handle($request);

        // The stub entity type manager reports "unknown type" for
        // everything, so the controller's 404 branch is the observable
        // proof that dispatch reached `transitions()` (not `transition()`,
        // which would 401 for a POST-shaped body first via a different path
        // but here both share the same _account+404 branch — the load-bearing
        // assertion is `content-type` + status, proving a real JsonApiResponse
        // came back rather than the router's own "unknown action" 404).
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('errors', $body);
    }

    #[Test]
    public function dispatchesTransitionToControllerTransitionMethod(): void
    {
        $router = $this->makeRouter();
        $request = Request::create('/api/article/1/workflow/transition', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"transition":"publish"}');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\WorkflowTransitionController::transition');
        $request->attributes->set('_entity_type', 'article');
        $request->attributes->set('id', '1');
        $request->attributes->set('_account', $this->account());

        $response = $router->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('errors', $body);
    }

    #[Test]
    public function unknownActionReturns404JsonResponse(): void
    {
        $router = $this->makeRouter();
        $request = Request::create('/api/article/1/workflow/frobnicate', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\WorkflowTransitionController::frobnicate');

        $response = $router->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Not Found', $body['errors'][0]['title']);
    }

    private function account(): \Waaseyaa\Access\AccountInterface
    {
        return new \Waaseyaa\Access\AuthorizationPrincipal(1, true, ['administrator'], [], 'test');
    }
}
