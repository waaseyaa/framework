<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\ReadModel;

use Waaseyaa\Api\AiObservability\Runs\RunDetail;
use Waaseyaa\Api\AiObservability\Runs\RunDetailReadModelInterface;
use Waaseyaa\Api\AiObservability\Runs\RunListRow;
use Waaseyaa\Api\AiObservability\Runs\RunSpanNode;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Reads full run detail including the recursive span tree.
 *
 * Recursion is bounded at 32 levels; deeper trees mark the boundary node
 * `truncated: true`.
 */
final class RunDetailReadModel implements RunDetailReadModelInterface
{
    private const int MAX_DEPTH = 32;

    public function __construct(
        private readonly EntityRepositoryInterface $traces,
        private readonly DatabaseInterface $database,
    ) {}

    public function findByUuid(string $traceUuid): ?RunDetail
    {
        $matches = $this->traces->findBy(['uuid' => $traceUuid]);
        if ($matches === []) {
            return null;
        }

        $trace = reset($matches);
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

        // Load all spans for this trace
        $spanRows = $this->database->select('trace_span', 'ts')
            ->fields('ts')
            ->condition('trace_uuid', $traceUuid)
            ->execute();

        $spansById = [];
        $spanCount = 0;
        $costUsd = 0.0;
        $totalTokens = 0;

        foreach ($spanRows as $spanRow) {
            $spanCount++;
            $uuid = (string) ($spanRow['uuid'] ?? '');
            $attrs = [];
            try {
                $decoded = json_decode($spanRow['attributes'] ?? '', true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $attrs = $decoded;
                    if (($spanRow['kind'] ?? '') === 'llm_call') {
                        $costUsd += (float) ($attrs['cost_usd'] ?? 0.0);
                        $totalTokens += (int) ($attrs['input_tokens'] ?? 0) + (int) ($attrs['output_tokens'] ?? 0);
                    }
                }
            } catch (\JsonException) {
                // Malformed attributes — skip, not fatal
            }

            $spanStartedAt = (string) ($spanRow['started_at'] ?? '');
            $spanEndedAt = isset($spanRow['ended_at']) && is_string($spanRow['ended_at']) ? $spanRow['ended_at'] : null;
            $spanDurationMs = null;
            if ($spanStartedAt !== '' && $spanEndedAt !== null) {
                try {
                    $s = new \DateTimeImmutable($spanStartedAt);
                    $e = new \DateTimeImmutable($spanEndedAt);
                    $spanDurationMs = (int) (($e->getTimestamp() - $s->getTimestamp()) * 1000);
                } catch (\Exception) {
                    $spanDurationMs = null;
                }
            }

            $spansById[$uuid] = [
                'spanUuid' => $uuid,
                'parentSpanUuid' => isset($spanRow['parent_span_uuid']) && is_string($spanRow['parent_span_uuid']) && $spanRow['parent_span_uuid'] !== '' ? $spanRow['parent_span_uuid'] : null,
                'kind' => (string) ($spanRow['kind'] ?? ''),
                'name' => (string) ($spanRow['name'] ?? ''),
                'status' => (string) ($spanRow['status'] ?? ''),
                'startedAt' => $spanStartedAt,
                'endedAt' => $spanEndedAt,
                'durationMs' => $spanDurationMs,
                'attributes' => $attrs,
                'children' => [],
            ];
        }

        // Build tree
        $roots = $this->buildTree($spansById);

        $header = new RunListRow(
            traceUuid: $traceUuid,
            pipeline: (string) ($trace->get('label') ?? ''),
            status: (string) ($trace->get('status') ?? ''),
            startedAt: $startedAt,
            endedAt: $endedAtStr,
            durationMs: $durationMs,
            costUsd: $costUsd,
            totalTokens: $totalTokens,
            spanCount: $spanCount,
        );

        return new RunDetail(header: $header, spans: $roots);
    }

    /**
     * Build a recursive span tree from a flat map of spans.
     *
     * @param array<string, array<string, mixed>> $spansById
     * @return list<RunSpanNode>
     */
    private function buildTree(array $spansById): array
    {
        // Group children by parent
        $childrenMap = [];
        $rootUuids = [];

        foreach ($spansById as $uuid => $span) {
            $parent = $span['parentSpanUuid'];
            if ($parent === null || !isset($spansById[$parent])) {
                $rootUuids[] = $uuid;
            } else {
                $childrenMap[$parent][] = $uuid;
            }
        }

        $roots = [];
        foreach ($rootUuids as $uuid) {
            $roots[] = $this->buildNode($uuid, $spansById, $childrenMap, 0);
        }

        return $roots;
    }

    /**
     * @param array<string, array<string, mixed>> $spansById
     * @param array<string, list<string>> $childrenMap
     */
    private function buildNode(string $uuid, array $spansById, array $childrenMap, int $depth): RunSpanNode
    {
        $span = $spansById[$uuid];

        if ($depth >= self::MAX_DEPTH) {
            return new RunSpanNode(
                spanUuid: $span['spanUuid'],
                parentSpanUuid: $span['parentSpanUuid'],
                kind: $span['kind'],
                name: $span['name'],
                status: $span['status'],
                startedAt: $span['startedAt'],
                endedAt: $span['endedAt'],
                durationMs: $span['durationMs'],
                attributes: $span['attributes'],
                children: [],
                truncated: true,
            );
        }

        $childNodes = [];
        foreach ($childrenMap[$uuid] ?? [] as $childUuid) {
            $childNodes[] = $this->buildNode($childUuid, $spansById, $childrenMap, $depth + 1);
        }

        return new RunSpanNode(
            spanUuid: $span['spanUuid'],
            parentSpanUuid: $span['parentSpanUuid'],
            kind: $span['kind'],
            name: $span['name'],
            status: $span['status'],
            startedAt: $span['startedAt'],
            endedAt: $span['endedAt'],
            durationMs: $span['durationMs'],
            attributes: $span['attributes'],
            children: $childNodes,
            truncated: false,
        );
    }
}
