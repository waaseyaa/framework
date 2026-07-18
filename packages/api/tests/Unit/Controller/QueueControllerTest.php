<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Controller\QueueController;
use Waaseyaa\Queue\Exception\InvalidPersistentPayload;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\PersistentPayloadReplayInterface;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\Transport\TransportInterface;

#[CoversClass(QueueController::class)]
final class QueueControllerTest extends TestCase
{
    #[Test]
    public function indexReturnsPaginatedJobsWithMeta(): void
    {
        $repo = $this->makeRepo([
            self::record('1', 'default', 'p-1'),
            self::record('2', 'high', 'p-2'),
            self::record('3', 'default', 'p-3'),
        ]);
        $controller = new QueueController($repo, $this->makeQueue());

        $payload = $controller->index(Request::create('/api/queue/jobs?page=1&per_page=2'));

        self::assertCount(2, $payload['data']);
        self::assertSame(['page' => 1, 'per_page' => 2, 'total' => 3], $payload['meta']);
        self::assertSame('1', $payload['data'][0]['id']);
        self::assertSame('default', $payload['data'][0]['queue']);
        self::assertSame('p-1', $payload['data'][0]['payload']);
        self::assertFalse($payload['data'][0]['payload_truncated']);
        self::assertSame('RuntimeException', $payload['data'][0]['exception_class']);
        self::assertSame('boom', $payload['data'][0]['exception_message']);
    }

    #[Test]
    public function indexPaginatesAcrossPages(): void
    {
        $repo = $this->makeRepo([
            self::record('1', 'default', 'p-1'),
            self::record('2', 'default', 'p-2'),
            self::record('3', 'default', 'p-3'),
        ]);
        $controller = new QueueController($repo, $this->makeQueue());

        $payload = $controller->index(Request::create('/api/queue/jobs?page=2&per_page=2'));

        self::assertCount(1, $payload['data']);
        self::assertSame('3', $payload['data'][0]['id']);
        self::assertSame(2, $payload['meta']['page']);
    }

    #[Test]
    public function indexDefaultsPerPageAndPage(): void
    {
        $repo = $this->makeRepo([]);
        $controller = new QueueController($repo, $this->makeQueue());

        $payload = $controller->index(Request::create('/api/queue/jobs'));

        self::assertSame(1, $payload['meta']['page']);
        self::assertSame(20, $payload['meta']['per_page']);
        self::assertSame(0, $payload['meta']['total']);
        self::assertSame([], $payload['data']);
    }

    #[Test]
    public function indexClampsPerPageToMax(): void
    {
        $repo = $this->makeRepo([]);
        $controller = new QueueController($repo, $this->makeQueue());

        $payload = $controller->index(Request::create('/api/queue/jobs?per_page=9999'));

        self::assertSame(100, $payload['meta']['per_page']);
    }

    #[Test]
    public function indexTruncatesLargePayloads(): void
    {
        $bigPayload = str_repeat('A', 3_000);
        $repo = $this->makeRepo([self::record('1', 'default', $bigPayload)]);
        $controller = new QueueController($repo, $this->makeQueue());

        $payload = $controller->index(Request::create('/api/queue/jobs'));

        self::assertTrue($payload['data'][0]['payload_truncated']);
        self::assertSame(2048, strlen($payload['data'][0]['payload']));
    }

    #[Test]
    public function retryDispatchesAndReturns204(): void
    {
        $message = new \stdClass();
        $serialized = serialize($message);
        $repo = $this->makeRepo([self::record('42', 'default', $serialized)]);
        $queue = $this->makeQueue();
        $controller = new QueueController($repo, $queue);

        $response = $controller->retry('42');

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertCount(1, $queue->dispatched);
        // Repo was consumed by retry().
        self::assertNull($repo->find('42'));
    }

    #[Test]
    public function retryReturns404WhenNotFound(): void
    {
        $repo = $this->makeRepo([]);
        $queue = $this->makeQueue();
        $controller = new QueueController($repo, $queue);

        $response = $controller->retry('does-not-exist');

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $queue->dispatched);
    }

    #[Test]
    public function retryReturns422WhenPayloadIsCorruptAndKeepsRecord(): void
    {
        $repo = $this->makeRepo([self::record('99', 'default', 'not-valid-php-serialize')]);
        $queue = $this->makeQueue();
        $controller = new QueueController($repo, $queue);

        $response = $controller->retry('99');

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $queue->dispatched);
        // Data-loss regression guard (#1915, R16): a corrupt payload must NOT
        // be forgotten from the failed table — the operator needs the row to
        // inspect or discard it explicitly.
        self::assertNotNull($repo->find('99'));
    }

    #[Test]
    public function retryLeavesRecordInPlaceWhenDispatchThrows(): void
    {
        $message = new \stdClass();
        $serialized = serialize($message);
        $repo = $this->makeRepo([self::record('55', 'default', $serialized)]);
        $queue = $this->makeThrowingQueue();
        $controller = new QueueController($repo, $queue);

        $response = $controller->retry('55');

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(502, $response->getStatusCode());
        // Data-loss regression guard (#1915, R16): a failed dispatch must NOT
        // forget the record — the job would otherwise be lost permanently.
        self::assertNotNull($repo->find('55'));
    }

    #[Test]
    public function retryDoesNotDispatchWhenAnotherCallerOwnsTheClaim(): void
    {
        $repo = new \Waaseyaa\Queue\Storage\InMemoryFailedJobRepository();
        $id = $repo->record('default', serialize(new \stdClass()), new \RuntimeException('failed'));
        self::assertTrue($repo->claimForRetry($id));
        $queue = $this->makeQueue();

        $response = new QueueController($repo, $queue)->retry($id);

        self::assertSame(409, $response->getStatusCode());
        self::assertCount(0, $queue->dispatched);
    }

    #[Test]
    public function persistentRetryPreservesExactPayloadAndQueue(): void
    {
        $payload = 'signed-envelope-bytes';
        $repo = $this->makeRepo([self::record('72', 'priority', $payload)]);
        $queue = new class implements QueueInterface, PersistentPayloadReplayInterface {
            public ?array $replayed = null;
            public function dispatch(object $message): void
            {
                self::fail('Persistent replay must preserve the envelope.');
            }
            public function replaySignedPayload(string $queue, string $signedPayload): void
            {
                $this->replayed = [$queue, $signedPayload];
            }
        };

        $response = new QueueController($repo, $queue)->retry('72');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(['priority', $payload], $queue->replayed);
    }

    #[Test]
    public function persistentRetryMapsInvalidSignedPayloadTo422AndReleasesClaim(): void
    {
        $repo = $this->makeRepo([self::record('73', 'priority', 'invalid')]);
        $queue = new class implements QueueInterface, PersistentPayloadReplayInterface {
            public function dispatch(object $message): void {}
            public function replaySignedPayload(string $queue, string $signedPayload): void
            {
                throw new InvalidPersistentPayload('Queue payload authentication failed.');
            }
        };

        $response = new QueueController($repo, $queue)->retry('73');

        self::assertSame(422, $response->getStatusCode());
        self::assertNotNull($repo->find('73'));
    }

    #[Test]
    public function discardForgetsAndReturns204(): void
    {
        $repo = $this->makeRepo([self::record('7', 'default', 'p')]);
        $controller = new QueueController($repo, $this->makeQueue());

        $response = $controller->discard('7');

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull($repo->find('7'));
    }

    #[Test]
    public function discardReturns404WhenNotFound(): void
    {
        $repo = $this->makeRepo([]);
        $controller = new QueueController($repo, $this->makeQueue());

        $response = $controller->discard('does-not-exist');

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function indexDefaultsToFailedShapeWhenNoStatusGiven(): void
    {
        $repo = $this->makeRepo([self::record('1', 'default', 'p-1')]);
        $controller = new QueueController($repo, $this->makeQueue(), $this->makeTransport([]));

        $payload = $controller->index(Request::create('/api/queue/jobs'));

        self::assertSame(1, $payload['meta']['total']);
        self::assertSame('1', $payload['data'][0]['id']);
        // Failed-row shape: carries exception fields.
        self::assertSame('RuntimeException', $payload['data'][0]['exception_class']);
    }

    #[Test]
    public function indexQueuedStatusUsesTransportListJobs(): void
    {
        $repo = $this->makeRepo([]);
        $transport = $this->makeTransport([
            self::transportRow(1, 'default', 'queued-payload', 'queued'),
        ]);
        $controller = new QueueController($repo, $this->makeQueue(), $transport);

        $payload = $controller->index(Request::create('/api/queue/jobs?status=queued'));

        self::assertSame(1, $payload['meta']['total']);
        self::assertSame('1', $payload['data'][0]['id']);
        self::assertSame('queued', $payload['data'][0]['status']);
        // Transport-row shape: no exception fields, has status + reserved_at.
        self::assertArrayNotHasKey('exception_class', $payload['data'][0]);
        self::assertArrayHasKey('reserved_at', $payload['data'][0]);
    }

    #[Test]
    public function indexInProgressStatusUsesTransportListJobs(): void
    {
        $repo = $this->makeRepo([]);
        $transport = $this->makeTransport([
            self::transportRow(7, 'high', 'live', 'in_progress'),
        ]);
        $controller = new QueueController($repo, $this->makeQueue(), $transport);

        $payload = $controller->index(Request::create('/api/queue/jobs?status=in_progress'));

        self::assertSame('7', $payload['data'][0]['id']);
        self::assertSame('in_progress', $payload['data'][0]['status']);
    }

    #[Test]
    public function indexAllStatusMergesFailedThenTransportRows(): void
    {
        $repo = $this->makeRepo([
            self::record('99', 'default', 'failed-payload'),
        ]);
        $transport = $this->makeTransport([
            self::transportRow(1, 'default', 'queued-a', 'queued'),
            self::transportRow(2, 'default', 'inflight-b', 'in_progress'),
        ]);
        $controller = new QueueController($repo, $this->makeQueue(), $transport);

        $payload = $controller->index(Request::create('/api/queue/jobs?status=all'));

        // 1 failed + 2 transport = 3 total
        self::assertSame(3, $payload['meta']['total']);
        self::assertCount(3, $payload['data']);
        // Failed row comes first, carries exception_class.
        self::assertSame('99', $payload['data'][0]['id']);
        self::assertSame('RuntimeException', $payload['data'][0]['exception_class']);
        // Transport rows follow, carry status.
        self::assertSame('queued', $payload['data'][1]['status']);
        self::assertSame('in_progress', $payload['data'][2]['status']);
    }

    #[Test]
    public function indexFallsBackToFailedWhenTransportAbsent(): void
    {
        $repo = $this->makeRepo([self::record('1', 'default', 'p-1')]);
        // Constructed without a transport — must degrade gracefully.
        $controller = new QueueController($repo, $this->makeQueue());

        $payload = $controller->index(Request::create('/api/queue/jobs?status=queued'));

        // Falls back to failed-list shape even though caller asked for queued.
        self::assertSame(1, $payload['meta']['total']);
        self::assertSame('RuntimeException', $payload['data'][0]['exception_class']);
    }

    #[Test]
    public function indexInvalidStatusFallsBackToFailed(): void
    {
        $repo = $this->makeRepo([self::record('1', 'default', 'p-1')]);
        $controller = new QueueController($repo, $this->makeQueue(), $this->makeTransport([]));

        $payload = $controller->index(Request::create('/api/queue/jobs?status=bogus'));

        // Falls back to the failed-list shape on a bogus status.
        self::assertSame('RuntimeException', $payload['data'][0]['exception_class']);
    }

    /**
     * @param list<array{id: string, queue: string, payload: string, exception: string, failed_at: string}> $records
     */
    private function makeRepo(array $records): object
    {
        return new class ($records) implements FailedJobRepositoryInterface {
            /** @var array<string, array{id: string, queue: string, payload: string, exception: string, failed_at: string}> */
            private array $records = [];

            /**
             * @param list<array{id: string, queue: string, payload: string, exception: string, failed_at: string}> $records
             */
            public function __construct(array $records)
            {
                foreach ($records as $r) {
                    $this->records[$r['id']] = $r;
                }
            }

            public function record(string $queue, string $payload, \Throwable $e): string
            {
                $id = (string) (count($this->records) + 1);
                $this->records[$id] = [
                    'id' => $id,
                    'queue' => $queue,
                    'payload' => $payload,
                    'exception' => get_class($e) . ': ' . $e->getMessage(),
                    'failed_at' => '2026-05-24T00:00:00+00:00',
                ];

                return $id;
            }

            public function all(): array
            {
                return $this->records;
            }

            public function find(string $id): ?array
            {
                return $this->records[$id] ?? null;
            }

            public function forget(string $id): void
            {
                unset($this->records[$id]);
            }

            public function flush(): void
            {
                $this->records = [];
            }

            public function retry(string $id): ?array
            {
                $r = $this->records[$id] ?? null;
                if ($r !== null) {
                    unset($this->records[$id]);
                }

                return $r;
            }

            public function claimForRetry(string $id): bool
            {
                return isset($this->records[$id]);
            }

            public function releaseRetryClaim(string $id): void {}
        };
    }

    /**
     * @return object{dispatched: list<object>}
     */
    private function makeQueue(): object
    {
        return new class implements QueueInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $message): void
            {
                $this->dispatched[] = $message;
            }
        };
    }

    /**
     * A `QueueInterface` fake whose `dispatch()` always throws, for exercising
     * the retry() path where re-enqueue fails after the record was fetched.
     */
    private function makeThrowingQueue(): QueueInterface
    {
        return new class implements QueueInterface {
            public function dispatch(object $message): void
            {
                throw new \RuntimeException('transport unavailable');
            }
        };
    }

    /**
     * @return array{id: string, queue: string, payload: string, exception: string, failed_at: string}
     */
    private static function record(string $id, string $queue, string $payload): array
    {
        return [
            'id' => $id,
            'queue' => $queue,
            'payload' => $payload,
            'exception' => "RuntimeException: boom\n#0 /tmp/x.php:1",
            'failed_at' => '2026-05-24T00:00:00+00:00',
        ];
    }

    /**
     * Build an anonymous-class TransportInterface fake whose `listJobs()`
     * applies the documented filter/limit/offset semantics against a fixed
     * row set. Only the methods exercised by QueueController need to behave;
     * the others throw to flag accidental usage from tests.
     *
     * @param list<array{
     *   id: int|string,
     *   queue: string,
     *   payload: string,
     *   attempts: int,
     *   available_at: int,
     *   reserved_at: int|null,
     *   status: 'queued'|'in_progress'
     * }> $rows
     */
    private function makeTransport(array $rows): TransportInterface
    {
        return new class ($rows) implements TransportInterface {
            /**
             * @param list<array{
             *   id: int|string,
             *   queue: string,
             *   payload: string,
             *   attempts: int,
             *   available_at: int,
             *   reserved_at: int|null,
             *   status: 'queued'|'in_progress'
             * }> $rows
             */
            public function __construct(private array $rows) {}

            public function push(string $queue, string $payload, int $delay = 0): void
            {
                throw new \LogicException('push() not used by controller tests');
            }

            public function pop(string $queue): ?array
            {
                throw new \LogicException('pop() not used by controller tests');
            }

            public function ack(int|string $jobId): void {}

            public function reject(int|string $jobId): void {}

            public function release(int|string $jobId, int $delay = 0): void {}

            public function size(string $queue): int
            {
                return 0;
            }

            public function purge(string $queue): void {}

            public function listJobs(int $limit, int $offset = 0, ?string $status = null): array
            {
                $filtered = array_values(array_filter(
                    $this->rows,
                    static fn(array $r): bool => $status === null || $r['status'] === $status,
                ));
                $total = count($filtered);
                $window = $limit === 0 ? [] : array_slice($filtered, $offset, $limit);

                return ['data' => array_values($window), 'total' => $total];
            }
        };
    }

    /**
     * @param 'queued'|'in_progress' $status
     * @return array{
     *   id: int|string,
     *   queue: string,
     *   payload: string,
     *   attempts: int,
     *   available_at: int,
     *   reserved_at: int|null,
     *   status: 'queued'|'in_progress'
     * }
     */
    private static function transportRow(int $id, string $queue, string $payload, string $status): array
    {
        return [
            'id' => $id,
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => 0,
            'available_at' => 1_700_000_000,
            'reserved_at' => $status === 'in_progress' ? 1_700_000_500 : null,
            'status' => $status,
        ];
    }
}
