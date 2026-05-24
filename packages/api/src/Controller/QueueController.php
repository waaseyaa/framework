<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\QueueInterface;

/**
 * Admin-only HTTP controller for the failed-jobs queue dashboard (M4B WP01).
 *
 * Exposes three actions backing `/admin/queue`:
 *   - `index`   — paginated list of failed jobs (JSON envelope).
 *   - `retry`   — re-enqueues a failed job and removes it from the failed table.
 *   - `discard` — permanently forgets a failed job.
 *
 * Queued and in-flight jobs are intentionally out of scope: `TransportInterface`
 * has no `listJobs()` method (constraint C-001). A follow-up issue tracks adding
 * it and extending this surface with `?status=queued|in_progress|failed`.
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

    public function __construct(
        private readonly FailedJobRepositoryInterface $failedJobRepository,
        private readonly QueueInterface $queue,
    ) {}

    /**
     * `GET /api/queue/jobs?page=1&per_page=20` — list failed jobs (paginated).
     *
     * @return array{
     *   data: list<array{
     *     id: string,
     *     queue: string,
     *     payload: string,
     *     payload_truncated: bool,
     *     exception_class: string,
     *     exception_message: string,
     *     failed_at: string,
     *     attempts: int
     *   }>,
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

        $all = array_values($this->failedJobRepository->all());
        $total = count($all);
        $offset = ($page - 1) * $perPage;
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
     * `POST /api/queue/jobs/{id}/retry` — re-enqueue a failed job.
     *
     * Semantics: `FailedJobRepositoryInterface::retry()` returns the record
     * AND removes it from the failed table — it does NOT re-enqueue. We
     * mirror the CLI `QueueRetryHandler` and dispatch the unserialized
     * message onto `QueueInterface` ourselves.
     *
     * Returns 204 on success, 404 if the id is unknown, 422 if the payload
     * is corrupt (cannot be unserialized).
     */
    public function retry(string $id): Response
    {
        // Check existence *before* the destructive retry() call to avoid races
        // and to allow a clean 404 without consuming the record.
        if ($this->failedJobRepository->find($id) === null) {
            return self::errorResponse(404, 'Not Found', sprintf('Unknown failed job id: %s', $id));
        }

        $record = $this->failedJobRepository->retry($id);
        if ($record === null) {
            // Race: another caller forgot/retried between find() and retry().
            return self::errorResponse(404, 'Not Found', sprintf('Unknown failed job id: %s', $id));
        }

        $message = @unserialize($record['payload']);
        if ($message === false || !is_object($message)) {
            // Payload is irrecoverable. We've already removed it from the
            // failed table (retry() is destructive); surface a 422 so the
            // operator sees the corruption rather than a silent success.
            return self::errorResponse(
                422,
                'Unprocessable Entity',
                sprintf('Failed job [%s] has a corrupt payload and cannot be re-enqueued.', $id),
            );
        }

        $this->queue->dispatch($message);

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
