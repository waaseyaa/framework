<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\AiObservability\Runs\RunDetail;
use Waaseyaa\Api\AiObservability\Runs\RunDetailReadModelInterface;
use Waaseyaa\Api\AiObservability\Runs\RunListFilter;
use Waaseyaa\Api\AiObservability\Runs\RunListPage;
use Waaseyaa\Api\AiObservability\Runs\RunListReadModelInterface;
use Waaseyaa\Api\AiObservability\Runs\RunListRow;
use Waaseyaa\Api\AiObservability\Runs\RunReplayResult;
use Waaseyaa\Api\AiObservability\Runs\RunReplayServiceInterface;
use Waaseyaa\Api\AiObservability\Runs\RunSpanNode;
use Waaseyaa\Api\Controller\AiObservabilityRunsController;

#[CoversClass(AiObservabilityRunsController::class)]
final class AiObservabilityRunsControllerTest extends TestCase
{
    // --- index() ---

    #[Test]
    public function indexReturnsEmptyShapeWhenListModelIsNull(): void
    {
        $controller = new AiObservabilityRunsController();

        $payload = $controller->index([]);

        $this->assertSame([], $payload['data']['rows']);
        $this->assertSame(1, $payload['data']['page']);
        $this->assertSame(25, $payload['data']['perPage']);
        $this->assertSame(0, $payload['data']['total']);
    }

    #[Test]
    public function indexDelegatesToListModel(): void
    {
        $row = new RunListRow(
            traceUuid: 'trace-uuid-1',
            pipeline: 'my-pipeline',
            status: 'ok',
            startedAt: '2026-01-01 10:00:00',
            endedAt: '2026-01-01 10:00:05',
            durationMs: 5000,
            costUsd: 0.042,
            totalTokens: 1234,
            spanCount: 3,
        );

        $page = new RunListPage(rows: [$row], page: 1, perPage: 25, total: 1);

        $listModel = $this->createStub(RunListReadModelInterface::class);
        $listModel->method('recentRuns')->willReturn($page);

        $controller = new AiObservabilityRunsController(listModel: $listModel);
        $payload = $controller->index([]);

        $this->assertCount(1, $payload['data']['rows']);
        $this->assertSame('trace-uuid-1', $payload['data']['rows'][0]['traceUuid']);
        $this->assertSame('my-pipeline', $payload['data']['rows'][0]['pipeline']);
        $this->assertSame(5000, $payload['data']['rows'][0]['durationMs']);
        $this->assertSame(0.042, $payload['data']['rows'][0]['costUsd']);
        $this->assertSame(1, $payload['data']['page']);
        $this->assertSame(1, $payload['data']['total']);
    }

    #[Test]
    public function indexClampsPerPageToMax100(): void
    {
        $page = new RunListPage(rows: [], page: 1, perPage: 100, total: 0);

        $listModel = $this->createMock(RunListReadModelInterface::class);
        $listModel->expects($this->once())
            ->method('recentRuns')
            ->with($this->isInstanceOf(RunListFilter::class), 1, 100)
            ->willReturn($page);

        $controller = new AiObservabilityRunsController(listModel: $listModel);
        $controller->index(['per_page' => '9999']);
    }

    #[Test]
    public function indexClampsPageToMin1(): void
    {
        $page = new RunListPage(rows: [], page: 1, perPage: 25, total: 0);

        $listModel = $this->createMock(RunListReadModelInterface::class);
        $listModel->expects($this->once())
            ->method('recentRuns')
            ->with($this->isInstanceOf(RunListFilter::class), 1, 25)
            ->willReturn($page);

        $controller = new AiObservabilityRunsController(listModel: $listModel);
        $controller->index(['page' => '-5']);
    }

    // --- show() ---

    #[Test]
    public function showReturnsNullDataWhenDetailModelIsNull(): void
    {
        $controller = new AiObservabilityRunsController();

        $payload = $controller->show('some-uuid');

        $this->assertNull($payload['data']);
    }

    #[Test]
    public function showReturns404WhenTraceNotFound(): void
    {
        $detailModel = $this->createStub(RunDetailReadModelInterface::class);
        $detailModel->method('findByUuid')->willReturn(null);

        $controller = new AiObservabilityRunsController(detailModel: $detailModel);
        $payload = $controller->show('non-existent-uuid');

        $this->assertSame(404, $payload['status']);
        $this->assertNull($payload['data']);
    }

    #[Test]
    public function showReturnsDetailPayloadWhenFound(): void
    {
        $row = new RunListRow(
            traceUuid: 'trace-1',
            pipeline: 'pipe',
            status: 'ok',
            startedAt: '2026-01-01 10:00:00',
            endedAt: null,
            durationMs: null,
            costUsd: 0.0,
            totalTokens: 0,
            spanCount: 0,
        );
        $detail = new RunDetail(header: $row, spans: []);

        $detailModel = $this->createStub(RunDetailReadModelInterface::class);
        $detailModel->method('findByUuid')->willReturn($detail);

        $controller = new AiObservabilityRunsController(detailModel: $detailModel);
        $payload = $controller->show('trace-1');

        $this->assertSame('trace-1', $payload['data']['traceUuid']);
        $this->assertSame([], $payload['data']['spans']);
    }

    #[Test]
    public function showRendersSpanTreeRecursively(): void
    {
        $child = new RunSpanNode(
            spanUuid: 'child-1',
            parentSpanUuid: 'root-1',
            kind: 'tool_call',
            name: 'list_files',
            status: 'ok',
            startedAt: '2026-01-01 10:00:01',
            endedAt: null,
            durationMs: null,
            attributes: [],
            children: [],
            truncated: false,
        );
        $root = new RunSpanNode(
            spanUuid: 'root-1',
            parentSpanUuid: null,
            kind: 'agent',
            name: 'main',
            status: 'ok',
            startedAt: '2026-01-01 10:00:00',
            endedAt: null,
            durationMs: null,
            attributes: [],
            children: [$child],
            truncated: false,
        );
        $row = new RunListRow(
            traceUuid: 'trace-1',
            pipeline: 'pipe',
            status: 'ok',
            startedAt: '2026-01-01 10:00:00',
            endedAt: null,
            durationMs: null,
            costUsd: 0.0,
            totalTokens: 0,
            spanCount: 2,
        );
        $detail = new RunDetail(header: $row, spans: [$root]);

        $detailModel = $this->createStub(RunDetailReadModelInterface::class);
        $detailModel->method('findByUuid')->willReturn($detail);

        $controller = new AiObservabilityRunsController(detailModel: $detailModel);
        $payload = $controller->show('trace-1');

        $this->assertCount(1, $payload['data']['spans']);
        $rootArr = $payload['data']['spans'][0];
        $this->assertSame('root-1', $rootArr['spanUuid']);
        $this->assertCount(1, $rootArr['children']);
        $this->assertSame('child-1', $rootArr['children'][0]['spanUuid']);
    }

    // --- replay() ---

    #[Test]
    public function replayReturnsNullDataWhenReplayServiceIsNull(): void
    {
        $controller = new AiObservabilityRunsController();

        $payload = $controller->replay('trace-uuid');

        $this->assertNull($payload['data']);
    }

    #[Test]
    public function replayDelegatesToReplayService(): void
    {
        $replayResult = new RunReplayResult(
            newRunUuid: 'new-uuid-xyz',
            status: 'queued',
            startedAt: '2026-01-01 10:00:00',
        );

        $replayService = $this->createStub(RunReplayServiceInterface::class);
        $replayService->method('replay')->willReturn($replayResult);

        $controller = new AiObservabilityRunsController(replayService: $replayService);
        $payload = $controller->replay('original-uuid');

        $this->assertSame('new-uuid-xyz', $payload['data']['newRunUuid']);
        $this->assertSame('queued', $payload['data']['status']);
        $this->assertSame('2026-01-01 10:00:00', $payload['data']['startedAt']);
    }
}
