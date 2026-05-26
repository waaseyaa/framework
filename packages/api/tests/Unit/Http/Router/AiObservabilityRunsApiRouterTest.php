<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\AiObservability\Runs\RunDetail;
use Waaseyaa\Api\AiObservability\Runs\RunDetailReadModelInterface;
use Waaseyaa\Api\AiObservability\Runs\RunListPage;
use Waaseyaa\Api\AiObservability\Runs\RunListReadModelInterface;
use Waaseyaa\Api\AiObservability\Runs\RunListRow;
use Waaseyaa\Api\AiObservability\Runs\RunReplayResult;
use Waaseyaa\Api\AiObservability\Runs\RunReplayServiceInterface;
use Waaseyaa\Api\Controller\AiObservabilityRunsController;
use Waaseyaa\Api\Http\Router\AiObservabilityRunsApiRouter;

#[CoversClass(AiObservabilityRunsApiRouter::class)]
final class AiObservabilityRunsApiRouterTest extends TestCase
{
    private function makeRow(string $uuid = 'trace-1'): RunListRow
    {
        return new RunListRow(
            traceUuid: $uuid,
            pipeline: 'test-pipeline',
            status: 'ok',
            startedAt: '2026-01-01 10:00:00',
            endedAt: null,
            durationMs: null,
            costUsd: 0.0,
            totalTokens: 0,
            spanCount: 0,
        );
    }

    // --- supports() ---

    #[Test]
    public function supportsReturnsTrueForControllerRef(): void
    {
        $controller = new AiObservabilityRunsController();
        $router = new AiObservabilityRunsApiRouter($controller);

        $request = new Request();
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\AiObservabilityRunsController::index');

        $this->assertTrue($router->supports($request));
    }

    #[Test]
    public function supportsReturnsFalseForOtherController(): void
    {
        $controller = new AiObservabilityRunsController();
        $router = new AiObservabilityRunsApiRouter($controller);

        $request = new Request();
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\OtherController::index');

        $this->assertFalse($router->supports($request));
    }

    // --- handle() → index ---

    #[Test]
    public function handleIndexReturns200JsonApiResponse(): void
    {
        $page = new RunListPage(rows: [], page: 1, perPage: 25, total: 0);

        $listModel = $this->createStub(RunListReadModelInterface::class);
        $listModel->method('recentRuns')->willReturn($page);

        $controller = new AiObservabilityRunsController(listModel: $listModel);
        $router = new AiObservabilityRunsApiRouter($controller);

        $request = new Request();
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\AiObservabilityRunsController::index');

        $response = $router->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('vnd.api+json', $response->headers->get('Content-Type') ?? '');
    }

    // --- handle() → show (found) ---

    #[Test]
    public function handleShowReturns200WhenFound(): void
    {
        $detail = new RunDetail(header: $this->makeRow(), spans: []);

        $detailModel = $this->createStub(RunDetailReadModelInterface::class);
        $detailModel->method('findByUuid')->willReturn($detail);

        $controller = new AiObservabilityRunsController(detailModel: $detailModel);
        $router = new AiObservabilityRunsApiRouter($controller);

        $request = new Request();
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\AiObservabilityRunsController::show');
        $request->attributes->set('uuid', 'trace-1');

        $response = $router->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('trace-1', $body['data']['traceUuid']);
    }

    // --- handle() → show (not found) ---

    #[Test]
    public function handleShowReturns404WhenNotFound(): void
    {
        $detailModel = $this->createStub(RunDetailReadModelInterface::class);
        $detailModel->method('findByUuid')->willReturn(null);

        $controller = new AiObservabilityRunsController(detailModel: $detailModel);
        $router = new AiObservabilityRunsApiRouter($controller);

        $request = new Request();
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\AiObservabilityRunsController::show');
        $request->attributes->set('uuid', 'non-existent');

        $response = $router->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    // --- handle() → replay ---

    #[Test]
    public function handleReplayReturns201WithNewUuid(): void
    {
        $replayResult = new RunReplayResult(
            newRunUuid: 'new-uuid-abc',
            status: 'queued',
            startedAt: '2026-01-01 10:00:00',
        );

        $replayService = $this->createStub(RunReplayServiceInterface::class);
        $replayService->method('replay')->willReturn($replayResult);

        $controller = new AiObservabilityRunsController(replayService: $replayService);
        $router = new AiObservabilityRunsApiRouter($controller);

        $request = new Request();
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\AiObservabilityRunsController::replay');
        $request->attributes->set('uuid', 'original-uuid');

        $response = $router->handle($request);

        $this->assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('new-uuid-abc', $body['data']['newRunUuid']);
        $this->assertSame('queued', $body['data']['status']);
    }

    // --- handle() → unknown action ---

    #[Test]
    public function handleUnknownActionReturns404(): void
    {
        $controller = new AiObservabilityRunsController();
        $router = new AiObservabilityRunsApiRouter($controller);

        $request = new Request();
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\AiObservabilityRunsController::unknownAction');

        $response = $router->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }
}
