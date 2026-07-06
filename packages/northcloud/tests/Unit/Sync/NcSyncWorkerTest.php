<?php

declare(strict_types=1);

namespace Waaseyaa\NorthCloud\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\NorthCloud\Client\NorthCloudClient;
use Waaseyaa\NorthCloud\Sync\MapperRegistry;
use Waaseyaa\NorthCloud\Sync\NcHitToEntityMapperInterface;
use Waaseyaa\NorthCloud\Sync\NcSyncService;
use Waaseyaa\NorthCloud\Sync\NcSyncWorker;

/**
 * Round-trip coverage for the NorthCloud sync daemon (D-42).
 *
 * Drives NcSyncWorker::run() end-to-end through a real NcSyncService, a
 * stubbed NorthCloudClient (via the client's built-in httpClient callable
 * seam) and mocked entity storage, asserting the worker's observable
 * contract: it persists matching hits, writes an atomic status JSON file
 * after each cycle, honours its maxCycles bound and stop() signal, and
 * records a fetch failure without throwing.
 */
#[CoversClass(NcSyncWorker::class)]
final class NcSyncWorkerTest extends TestCase
{
    private string $statusPath;

    protected function setUp(): void
    {
        $this->statusPath = sys_get_temp_dir()
            . '/waaseyaa_nc_worker_' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->statusPath);
        @unlink($this->statusPath . '.tmp');
    }

    #[Test]
    public function singleCycleRoundTripPersistsHitAndWritesStatusFile(): void
    {
        $service = $this->serviceFor(
            response: ['hits' => [['id' => 'a']], 'total_hits' => 1],
            persistExpected: true,
        );

        $worker = new NcSyncWorker(
            syncService: $service,
            statusPath: $this->statusPath,
            intervalSeconds: 0,
            maxCycles: 1,
            limit: 20,
            statusSampleLimit: 0,
        );

        $worker->run();

        $status = $this->readStatus();
        $this->assertSame(1, $status['created']);
        $this->assertSame(1, $status['fetched']);
        $this->assertSame(0, $status['skipped']);
        $this->assertSame(0, $status['failed']);
        $this->assertFalse($status['fetch_failed']);
        $this->assertSame(1, $status['cycles']);
    }

    #[Test]
    public function maxCyclesBoundTerminatesTheLoop(): void
    {
        $service = $this->serviceFor(
            response: ['hits' => [], 'total_hits' => 0],
            persistExpected: false,
        );

        $worker = new NcSyncWorker(
            syncService: $service,
            statusPath: $this->statusPath,
            intervalSeconds: 0,
            maxCycles: 3,
            statusSampleLimit: 0,
        );

        $worker->run();

        // The loop must stop on its own once cycleCount reaches maxCycles.
        $this->assertSame(3, $this->readStatus()['cycles']);
    }

    #[Test]
    public function stopHaltsTheLoopAfterTheCurrentCycle(): void
    {
        // A mapper-less service that asks the worker to stop on its first
        // cycle proves stop() short-circuits the unbounded (maxCycles=0) loop
        // before a second cycle (and before sleep()).
        $worker = null;
        $service = $this->serviceFor(
            response: ['hits' => [], 'total_hits' => 0],
            persistExpected: false,
            onSync: static function () use (&$worker): void {
                $worker?->stop();
            },
        );

        $worker = new NcSyncWorker(
            syncService: $service,
            statusPath: $this->statusPath,
            intervalSeconds: 999,
            maxCycles: 0,
            statusSampleLimit: 0,
        );

        $worker->run();

        $this->assertSame(1, $this->readStatus()['cycles']);
    }

    #[Test]
    public function fetchFailureIsRecordedWithoutThrowing(): void
    {
        $service = $this->serviceFor(
            response: null, // httpClient returns false -> client returns null
            persistExpected: false,
        );

        $worker = new NcSyncWorker(
            syncService: $service,
            statusPath: $this->statusPath,
            intervalSeconds: 0,
            maxCycles: 1,
            statusSampleLimit: 0,
        );

        $worker->run();

        $status = $this->readStatus();
        $this->assertTrue($status['fetch_failed']);
        $this->assertSame(0, $status['created']);
    }

    /**
     * @param array<string, mixed>|null $response
     * @param (callable():void)|null $onSync invoked once per sync cycle
     */
    private function serviceFor(
        ?array $response,
        bool $persistExpected,
        ?callable $onSync = null,
    ): NcSyncService {
        $client = new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: new WorkerFakeHttpClient(
                static function () use ($response, $onSync): HttpResponse {
                    if ($onSync !== null) {
                        $onSync();
                    }

                    if ($response === null) {
                        throw new HttpRequestException('stub transport failure', 'https://nc.test', 'GET');
                    }

                    return new HttpResponse(200, (string) json_encode($response));
                },
            ),
        );

        $storages = [];
        if ($persistExpected) {
            $entity = $this->createStub(EntityInterface::class);
            $storage = $this->createMock(EntityStorageInterface::class);
            $storage->method('getQuery')->willReturn($this->emptyQuery());
            $storage->method('create')->willReturn($entity);
            $storage->method('save')->willReturn(1);
            $storages['thing'] = $storage;
        }

        $registry = new MapperRegistry();
        $registry->register(new WorkerFakeMapper(
            dedup: 'source_url',
            map: ['source_url' => 'https://x', 'title' => 'T'],
        ));

        return new NcSyncService(
            $client,
            $this->entityTypeManager($storages),
            $registry,
        );
    }

    private function emptyQuery(): EntityQueryInterface
    {
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('execute')->willReturn([]);
        return $query;
    }

    /**
     * @param array<string, EntityStorageInterface> $storages
     */
    private function entityTypeManager(array $storages): EntityTypeManager
    {
        return new class($storages) extends EntityTypeManager {
            /** @param array<string, EntityStorageInterface> $storages */
            public function __construct(private readonly array $storages) {}

            public function getStorage(string $entityTypeId): EntityStorageInterface
            {
                if (!isset($this->storages[$entityTypeId])) {
                    throw new \RuntimeException("No stub storage for $entityTypeId");
                }
                return $this->storages[$entityTypeId];
            }

            // C-22 WP2/WP3: both the query surface and the create/save path now live on the repository.
            public function getRepository(string $entityTypeId): \Waaseyaa\Entity\Repository\EntityRepositoryInterface
            {
                return new \Waaseyaa\Entity\Testing\StorageBackedStubRepository($this->getStorage($entityTypeId));
            }
        };
    }

    /**
     * @return array{created: int, skipped: int, failed: int, fetch_failed: bool, fetched: int, cycles: int}
     */
    private function readStatus(): array
    {
        $raw = file_get_contents($this->statusPath);
        $this->assertNotFalse($raw, 'Status file must be written');

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return [
            'created' => (int) $decoded['created'],
            'skipped' => (int) $decoded['skipped'],
            'failed' => (int) $decoded['failed'],
            'fetch_failed' => (bool) $decoded['fetch_failed'],
            'fetched' => (int) $decoded['fetched'],
            'cycles' => (int) $decoded['cycles'],
        ];
    }
}

/**
 * Minimal fake mapper for NcSyncWorkerTest.
 */
final class WorkerFakeMapper implements NcHitToEntityMapperInterface
{
    /**
     * @param array<string, mixed> $map
     */
    public function __construct(
        private readonly string $dedup,
        private readonly array $map,
        private readonly string $entityType = 'thing',
    ) {}

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function supports(array $hit): bool
    {
        return true;
    }

    public function map(array $hit): array
    {
        return $this->map;
    }

    public function dedupField(): string
    {
        return $this->dedup;
    }
}

/**
 * Test double for HttpClientInterface, local to this file. Named "Worker" (not
 * plain FakeHttpClient) because NcSyncServiceTest.php shares this namespace
 * (Unit\Sync) and declares its own SyncFakeHttpClient to avoid a class-name
 * collision when both files load in the same PHPUnit run.
 */
final class WorkerFakeHttpClient implements HttpClientInterface
{
    /** @var \Closure(string, string, array<string, string>, array<string, mixed>|string|null): HttpResponse */
    private readonly \Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler(...);
    }

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        return ($this->handler)($method, $url, $headers, $body);
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body);
    }
}
