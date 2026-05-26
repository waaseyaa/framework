<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\ReadModel;

use Waaseyaa\Api\AiObservability\Runs\RunListFilter;
use Waaseyaa\Api\AiObservability\Runs\RunListPage;
use Waaseyaa\Api\AiObservability\Runs\RunListReadModelInterface;
use Waaseyaa\Api\AiObservability\Runs\RunListRow;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Reads paginated + filtered AI observability runs from the trace entity store.
 *
 * Adapts the api-local RunListReadModelInterface (L4) from within ai-observability (L5).
 * This is allowed: higher layer implementing lower-layer interface.
 *
 * Uses `DatabaseInterface::select()` for span aggregation; never `getQuery()` (getquery gate).
 */
final class RunListReadModel implements RunListReadModelInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $traces,
        private readonly DatabaseInterface $database,
    ) {}

    public function recentRuns(RunListFilter $filter, int $page, int $perPage): RunListPage
    {
        // Clamp pagination
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        // Build conditions for the trace entity table
        $conditions = [];
        if ($filter->pipeline !== null) {
            $conditions['label'] = $filter->pipeline;
        }
        if ($filter->status !== null) {
            $conditions['status'] = $filter->status;
        }

        // Fetch all matching traces for count, then slice for pagination.
        // trace entities are stored with started_at as a plain column field.
        // We rely on EntityRepository::findBy for entity hydration.
        $allTraces = $this->traces->findBy($conditions);

        // Apply date range filter PHP-side (entity storage doesn't expose range queries)
        if ($filter->from !== null || $filter->to !== null) {
            $allTraces = array_filter($allTraces, function (mixed $trace) use ($filter): bool {
                /** @var \Waaseyaa\AI\Observability\Trace $trace */
                $startedAt = $trace->get('started_at');
                if (!is_string($startedAt)) {
                    return true;
                }
                try {
                    $dt = new \DateTimeImmutable($startedAt);
                } catch (\Exception) {
                    return true;
                }
                if ($filter->from !== null && $dt < $filter->from) {
                    return false;
                }
                if ($filter->to !== null && $dt > $filter->to) {
                    return false;
                }
                return true;
            });
            $allTraces = array_values($allTraces);
        }

        // Sort newest first by started_at
        usort($allTraces, static function (mixed $a, mixed $b): int {
            /** @var \Waaseyaa\AI\Observability\Trace $a */
            /** @var \Waaseyaa\AI\Observability\Trace $b */
            $aAt = (string) ($a->get('started_at') ?? '');
            $bAt = (string) ($b->get('started_at') ?? '');
            return strcmp($bAt, $aAt);
        });

        $total = count($allTraces);
        $offset = ($page - 1) * $perPage;
        $pageTraces = array_slice($allTraces, $offset, $perPage);

        $rows = [];
        foreach ($pageTraces as $trace) {
            /** @var \Waaseyaa\AI\Observability\Trace $trace */
            $traceUuid = (string) ($trace->get('uuid') ?? '');
            $rows[] = $this->buildRow($traceUuid, $trace);
        }

        return new RunListPage(
            rows: $rows,
            page: $page,
            perPage: $perPage,
            total: $total,
        );
    }

    private function buildRow(string $traceUuid, mixed $trace): RunListRow
    {
        /** @var \Waaseyaa\AI\Observability\Trace $trace */
        $startedAt = (string) ($trace->get('started_at') ?? '');
        $endedAt = $trace->get('ended_at');
        $endedAtStr = is_string($endedAt) ? $endedAt : null;

        $durationMs = null;
        if ($startedAt !== '' && $endedAtStr !== null) {
            try {
                $start = new \DateTimeImmutable($startedAt);
                $end = new \DateTimeImmutable($endedAtStr);
                $durationMs = (int) (($end->getTimestamp() - $start->getTimestamp()) * 1000);
            } catch (\Exception) {
                $durationMs = null;
            }
        }

        // Aggregate cost + tokens + span count from trace_span table
        $aggResult = $this->aggregateSpans($traceUuid);

        return new RunListRow(
            traceUuid: $traceUuid,
            pipeline: (string) ($trace->get('label') ?? ''),
            status: (string) ($trace->get('status') ?? ''),
            startedAt: $startedAt,
            endedAt: $endedAtStr,
            durationMs: $durationMs,
            costUsd: $aggResult['costUsd'],
            totalTokens: $aggResult['totalTokens'],
            spanCount: $aggResult['spanCount'],
        );
    }

    /**
     * @return array{costUsd: float, totalTokens: int, spanCount: int}
     */
    private function aggregateSpans(string $traceUuid): array
    {
        $rows = $this->database->select('trace_span', 'ts')
            ->fields('ts', ['kind', 'attributes'])
            ->condition('trace_uuid', $traceUuid)
            ->execute();

        $costUsd = 0.0;
        $totalTokens = 0;
        $spanCount = 0;

        foreach ($rows as $row) {
            $spanCount++;
            if ($row['kind'] !== 'llm_call') {
                continue;
            }
            try {
                $attrs = json_decode($row['attributes'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($attrs)) {
                continue;
            }
            $costUsd += (float) ($attrs['cost_usd'] ?? 0.0);
            $totalTokens += (int) ($attrs['input_tokens'] ?? 0) + (int) ($attrs['output_tokens'] ?? 0);
        }

        return ['costUsd' => $costUsd, 'totalTokens' => $totalTokens, 'spanCount' => $spanCount];
    }
}
