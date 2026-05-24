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
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\QueueInterface;

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
    public function retryReturns422WhenPayloadIsCorrupt(): void
    {
        $repo = $this->makeRepo([self::record('99', 'default', 'not-valid-php-serialize')]);
        $queue = $this->makeQueue();
        $controller = new QueueController($repo, $queue);

        $response = $controller->retry('99');

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $queue->dispatched);
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
}
