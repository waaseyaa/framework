<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Queue\Exception\InvalidPersistentPayload;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\PersistentPayloadReplayInterface;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\Transport\TransportInterface;

/**
 * Admin-only HTTP controller for the failed-jobs queue dashboard (M4B WP01).
 *
 * Exposes three actions backing `/admin/queue`:
 *   - `index`   — paginated list of failed jobs (JSON envelope).
 *   - `retry`   — re-enqueues a failed job and removes it from the failed table.
 *   - `discard` — permanently forgets a failed job.
 *
 * Queued and in-flight jobs were originally out of scope (M4B C-001). The
 * follow-up GitHub issue #1576 added `TransportInterface::listJobs()`, and the
 * `index()` action now branches on `?status=failed|queued|in_progress|all`
 * (default `failed` for M4B backward compatibility — see NFR-001).
 *
 * Access control: enforced by the route option `_role: admin` (see
 * `BuiltinRouteRegistrar`). NFR-001 — do NOT re-check the role here.
 *
 * @api
 */
final class QueueController
{
    /**
     * Truncate payloads larger than this when listing, to keep the response
     * shape small. The View-payload action on the row currently uses the
     * truncated value (sufficient for the MVP); a future endpoint may serve
     * the full payload on demand.
     */
    private const int PAYLOAD_TRUNCATION_BYTES = 2048;

    /**
     * Default pagination size for the list endpoint.
     */
    private const int DEFAULT_PER_PAGE = 20;

    /**
     * Cap on `per_page` to prevent oversized list responses.
     */
    private const int MAX_PER_PAGE = 100;

    /**
     * Allowed values for the `?status` query parameter. Anything else falls
     * back to the M4B default `failed` so external callers never see a hard
     * 4xx for a typo (NFR-001).
     */
    private const array ALLOWED_STATUSES = ['failed', 'queued', 'in_progress', 'all'];

    public function __construct(
        private readonly FailedJobRepositoryInterface $failedJobRepository,
        private readonly QueueInterface $queue,
        private readonly ?TransportInterface $transport = null,
    ) {}

    /**
     * `GET /api/queue/jobs?page=1&per_page=20&status=failed|queued|in_progress|all`
     *
     * `?status` defaults to `failed` (M4B backward compatibility, NFR-001).
     * For `failed`, rows come from {@see FailedJobRepositoryInterface} and
     * carry the M4B failed-row shape. For `queued` / `in_progress`, rows
     * come from {@see TransportInterface::listJobs()} and carry the live-job
     * shape. For `all`, the response merges both — failed rows first, then
     * the transport's queued+in_progress window — to keep the failed-row
     * detail visible at the top of the table without forcing a join.
     *
     * If the transport is unavailable (slimmed-down install), the
     * `queued`/`in_progress`/`all` branches degrade to the failed list to
     * preserve the M4B response shape.
     *
     * @return array{
     *   data: list<array<string, mixed>>,
     *   meta: array{page: int, per_page: int, total: int}
     * }
     */
    public function index(Request $request): array
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = (int) $request->query->get('per_page', self::DEFAULT_PER_PAGE);
        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        $statusRaw = $request->query->get('status', 'failed');
        $status = in_array($statusRaw, self::ALLOWED_STATUSES, true)
            ? $statusRaw
            : 'failed';

        $offset = ($page - 1) * $perPage;

        if ($status === 'failed' || $this->transport === null) {
            return $this->indexFailed($page, $perPage, $offset);
        }

        if ($status === 'queued' || $status === 'in_progress') {
            return $this->indexLive($page, $perPage, $offset, $status);
        }

        // status === 'all' — failed rows first, then transport rows.
        return $this->indexAll($page, $perPage, $offset);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int}}
     */
    private function indexFailed(int $page, int $perPage, int $offset): array
    {
        $all = array_values($this->failedJobRepository->all());
        $total = count($all);
        $slice = array_slice($all, $offset, $perPage);

        $rows = [];
        foreach ($slice as $record) {
            $rows[] = self::serializeRecord($record);
        }

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @param 'queued'|'in_progress' $status
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int}}
     */
    private function indexLive(int $page, int $perPage, int $offset, string $status): array
    {
        // `indexLive()` is only reachable when transport !== null (see index()).
        \assert($this->transport !== null);

        $result = $this->transport->listJobs($perPage, $offset, $status);

        $rows = [];
        foreach ($result['data'] as $row) {
            $rows[] = self::serializeTransportRow($row);
        }

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
            ],
        ];
    }

    /**
     * Merge failed + transport (queued + in_progress) into a single page.
     *
     * MVP merge strategy (FR-008): failed first, then the transport window
     * filling the rest of the page. Total reflects the union of both sets.
     * Per-page pagination is page-local — sufficient for the chip-driven UX
     * but documented as such so future work can switch to a unified cursor.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int}}
     */
    private function indexAll(int $page, int $perPage, int $offset): array
    {
        \assert($this->transport !== null);

        $failedAll = array_values($this->failedJobRepository->all());
        $failedTotal = count($failedAll);

        // Page through the failed slice first; the remainder of the page is
        // filled from the transport, starting at the offset that "skipped" past
        // the failed rows.
        $failedSlice = array_slice($failedAll, $offset, $perPage);
        $remaining = $perPage - count($failedSlice);

        $transportOffset = max(0, $offset - $failedTotal);
        $transportLimit = $remaining > 0 ? $remaining : 0;
        $transportResult = $this->transport->listJobs($transportLimit, $transportOffset, null);

        $rows = [];
        foreach ($failedSlice as $record) {
            $rows[] = self::serializeRecord($record);
        }
        foreach ($transportResult['data'] as $row) {
            $rows[] = self::serializeTransportRow($row);
        }

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $failedTotal + $transportResult['total'],
            ],
        ];
    }

    /**
     * `POST /api/queue/jobs/{id}/retry` — re-enqueue a failed job.
     *
     * The payload is validated before an atomic repository claim. Only the
     * claim winner dispatches, closing the API/CLI same-id double-dispatch
     * window; a failed dispatch releases the claim and a successful one
     * removes the row.
     *
     * Returns 204 on success, 404 if the id is unknown, 409 if another retry
     * owns the claim, 422 for a corrupt payload, and 502 on dispatch failure.
     */
    public function retry(string $id): Response
    {
        $record = $this->failedJobRepository->find($id);
        if ($record === null) {
            return self::errorResponse(404, 'Not Found', sprintf('Unknown failed job id: %s', $id));
        }

        $message = null;
        if (!$this->queue instanceof PersistentPayloadReplayInterface) {
            $message = @unserialize($record['payload']);
        }
        if (!$this->queue instanceof PersistentPayloadReplayInterface && ($message === false || !is_object($message))) {
            // Payload is irrecoverable. Leave the record in the failed table
            // (unlike the pre-#1915-R16 behavior) so the operator can inspect
            // or explicitly discard it rather than losing it silently.
            return self::errorResponse(
                422,
                'Unprocessable Entity',
                sprintf('Failed job [%s] has a corrupt payload and cannot be re-enqueued.', $id),
            );
        }

        if (!$this->failedJobRepository->claimForRetry($id)) {
            return self::errorResponse(
                409,
                'Conflict',
                sprintf('Failed job [%s] is already being retried.', $id),
            );
        }

        try {
            if ($this->queue instanceof PersistentPayloadReplayInterface) {
                $this->queue->replaySignedPayload($record['queue'], $record['payload']);
            } else {
                \assert(is_object($message));
                $this->queue->dispatch($message);
            }
        } catch (InvalidPersistentPayload) {
            $this->failedJobRepository->releaseRetryClaim($id);

            return self::errorResponse(
                422,
                'Unprocessable Entity',
                sprintf('Failed job [%s] has an invalid persistent payload and cannot be re-enqueued.', $id),
            );
        } catch (\Throwable $e) {
            $this->failedJobRepository->releaseRetryClaim($id);
            // Dispatch failed — leave the record in the failed table so the
            // job is not lost; the operator can retry again once the
            // underlying issue (e.g. a transport outage) is resolved.
            return self::errorResponse(
                502,
                'Bad Gateway',
                sprintf('Failed job [%s] could not be re-dispatched: %s', $id, $e->getMessage()),
            );
        }

        // Only forget the failed-table row after a successful dispatch.
        $this->failedJobRepository->forget($id);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * `POST /api/queue/jobs/{id}/discard` — permanently drop a failed job.
     *
     * Returns 204 on success, 404 if the id is unknown.
     */
    public function discard(string $id): Response
    {
        if ($this->failedJobRepository->find($id) === null) {
            return self::errorResponse(404, 'Not Found', sprintf('Unknown failed job id: %s', $id));
        }

        $this->failedJobRepository->forget($id);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @param array{id: string, queue: string, payload: string, exception: string, failed_at: string} $record
     * @return array{
     *   id: string,
     *   queue: string,
     *   payload: string,
     *   payload_truncated: bool,
     *   exception_class: string,
     *   exception_message: string,
     *   failed_at: string,
     *   attempts: int
     * }
     */
    private static function serializeRecord(array $record): array
    {
        [$exceptionClass, $exceptionMessage] = self::splitException($record['exception']);

        $payload = $record['payload'];
        $truncated = false;
        if (strlen($payload) > self::PAYLOAD_TRUNCATION_BYTES) {
            $payload = substr($payload, 0, self::PAYLOAD_TRUNCATION_BYTES);
            $truncated = true;
        }

        return [
            'id' => $record['id'],
            'queue' => $record['queue'],
            'payload' => $payload,
            'payload_truncated' => $truncated,
            'exception_class' => $exceptionClass,
            'exception_message' => $exceptionMessage,
            'failed_at' => $record['failed_at'],
            // FailedJobRepositoryInterface does not currently expose `attempts`;
            // surface 0 here to keep the row shape stable for the SPA. When
            // the contract grows an attempts column, swap this to the real value.
            'attempts' => 0,
        ];
    }

    /**
     * Serialize a transport row (queued/in_progress) for the SPA. Distinct
     * from `serializeRecord` (failed rows) — failed rows carry exception
     * detail, transport rows carry timing detail. The SPA picks columns
     * based on the meta.status it receives.
     *
     * @param array{
     *   id: int|string,
     *   queue: string,
     *   payload: string,
     *   attempts: int,
     *   available_at: int,
     *   reserved_at: int|null,
     *   status: 'queued'|'in_progress'
     * } $row
     * @return array{
     *   id: string,
     *   queue: string,
     *   payload: string,
     *   payload_truncated: bool,
     *   attempts: int,
     *   available_at: int,
     *   reserved_at: int|null,
     *   status: 'queued'|'in_progress'
     * }
     */
    private static function serializeTransportRow(array $row): array
    {
        $payload = $row['payload'];
        $truncated = false;
        if (strlen($payload) > self::PAYLOAD_TRUNCATION_BYTES) {
            $payload = substr($payload, 0, self::PAYLOAD_TRUNCATION_BYTES);
            $truncated = true;
        }

        return [
            'id' => (string) $row['id'],
            'queue' => $row['queue'],
            'payload' => $payload,
            'payload_truncated' => $truncated,
            'attempts' => $row['attempts'],
            'available_at' => $row['available_at'],
            'reserved_at' => $row['reserved_at'],
            'status' => $row['status'],
        ];
    }

    /**
     * Split the stored exception string into a class/message pair.
     *
     * The stored format is implementation-defined — generally
     * `<FQCN>: <message>\n<trace>...`. We split on the first ":" + space,
     * fall back to whole-string-as-message if no class prefix is found.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitException(string $serialized): array
    {
        $newlinePos = strpos($serialized, "\n");
        $firstLine = $newlinePos === false ? $serialized : substr($serialized, 0, $newlinePos);

        $colonPos = strpos($firstLine, ': ');
        if ($colonPos === false) {
            return ['', trim($firstLine)];
        }

        return [
            trim(substr($firstLine, 0, $colonPos)),
            trim(substr($firstLine, $colonPos + 2)),
        ];
    }

    private static function errorResponse(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => (string) $status,
                'title' => $title,
                'detail' => $detail,
            ]],
        ], $status, ['Content-Type' => 'application/vnd.api+json']);
    }
}
