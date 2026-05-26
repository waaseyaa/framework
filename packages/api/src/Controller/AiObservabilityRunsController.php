<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Waaseyaa\Api\AiObservability\Runs\RunDetail;
use Waaseyaa\Api\AiObservability\Runs\RunDetailReadModelInterface;
use Waaseyaa\Api\AiObservability\Runs\RunListFilter;
use Waaseyaa\Api\AiObservability\Runs\RunListReadModelInterface;
use Waaseyaa\Api\AiObservability\Runs\RunListRow;
use Waaseyaa\Api\AiObservability\Runs\RunReplayServiceInterface;
use Waaseyaa\Api\AiObservability\Runs\RunSpanNode;

/**
 * Admin controller for AI observability runs endpoints (M5B).
 *
 * Three actions:
 *   - index  → GET  /api/ai/observability/runs
 *   - show   → GET  /api/ai/observability/runs/{uuid}
 *   - replay → POST /api/ai/observability/runs/{uuid}/replay
 *
 * All gated by `_role: admin` at the route level (NFR-001).
 * The replay action additionally requires the `ai.trace.replay` gate ability
 * (DIR-004) — enforced by AccessChecker via `_gate` route option.
 * Controller does NOT re-check either guard.
 *
 * Degrades cleanly when any dependency is null (disabled or absent install).
 */
final class AiObservabilityRunsController
{
    private const int PER_PAGE_MIN = 1;
    private const int PER_PAGE_MAX = 100;
    private const int PER_PAGE_DEFAULT = 25;
    private const int PAGE_MIN = 1;
    private const int PAGE_DEFAULT = 1;

    public function __construct(
        private readonly ?RunListReadModelInterface $listModel = null,
        private readonly ?RunDetailReadModelInterface $detailModel = null,
        private readonly ?RunReplayServiceInterface $replayService = null,
    ) {}

    /**
     * GET /api/ai/observability/runs
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function index(array $query = []): array
    {
        if ($this->listModel === null) {
            return ['data' => ['rows' => [], 'page' => 1, 'perPage' => self::PER_PAGE_DEFAULT, 'total' => 0]];
        }

        $page = max(self::PAGE_MIN, (int) ($query['page'] ?? self::PAGE_DEFAULT));
        $perPage = min(self::PER_PAGE_MAX, max(self::PER_PAGE_MIN, (int) ($query['perPage'] ?? self::PER_PAGE_DEFAULT)));

        $filter = RunListFilter::fromQuery($query);
        $pageResult = $this->listModel->recentRuns($filter, $page, $perPage);

        return [
            'data' => [
                'rows' => array_map(self::rowToArray(...), $pageResult->rows),
                'page' => $pageResult->page,
                'perPage' => $pageResult->perPage,
                'total' => $pageResult->total,
            ],
        ];
    }

    /**
     * GET /api/ai/observability/runs/{uuid}
     *
     * @return array<string, mixed>
     */
    public function show(string $uuid): array
    {
        if ($this->detailModel === null) {
            return ['data' => null];
        }

        $detail = $this->detailModel->findByUuid($uuid);
        if ($detail === null) {
            return ['status' => 404, 'data' => null];
        }

        return ['data' => self::detailToArray($detail)];
    }

    /**
     * POST /api/ai/observability/runs/{uuid}/replay
     *
     * @return array<string, mixed>
     */
    public function replay(string $uuid): array
    {
        if ($this->replayService === null) {
            return ['data' => null];
        }

        $result = $this->replayService->replay($uuid);

        return [
            'data' => [
                'newRunUuid' => $result->newRunUuid,
                'status' => $result->status,
                'startedAt' => $result->startedAt,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function rowToArray(RunListRow $row): array
    {
        return [
            'traceUuid' => $row->traceUuid,
            'pipeline' => $row->pipeline,
            'status' => $row->status,
            'startedAt' => $row->startedAt,
            'endedAt' => $row->endedAt,
            'durationMs' => $row->durationMs,
            'costUsd' => $row->costUsd,
            'totalTokens' => $row->totalTokens,
            'spanCount' => $row->spanCount,
        ];
    }

    /** @return array<string, mixed> */
    private static function detailToArray(RunDetail $detail): array
    {
        return [
            'header' => self::rowToArray($detail->header),
            'spans' => array_map(self::spanNodeToArray(...), $detail->spans),
        ];
    }

    /** @return array<string, mixed> */
    private static function spanNodeToArray(RunSpanNode $node): array
    {
        return [
            'spanUuid' => $node->spanUuid,
            'parentSpanUuid' => $node->parentSpanUuid,
            'kind' => $node->kind,
            'name' => $node->name,
            'status' => $node->status,
            'startedAt' => $node->startedAt,
            'endedAt' => $node->endedAt,
            'durationMs' => $node->durationMs,
            'attributes' => $node->attributes,
            'children' => array_map(self::spanNodeToArray(...), $node->children),
            'truncated' => $node->truncated,
        ];
    }
}
