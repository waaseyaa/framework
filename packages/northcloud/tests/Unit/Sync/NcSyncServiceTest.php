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
use Waaseyaa\NorthCloud\Client\NorthCloudClient;
use Waaseyaa\NorthCloud\Sync\NcHitSupportDiagnosticsInterface;
use Waaseyaa\NorthCloud\Sync\MapperRegistry;
use Waaseyaa\NorthCloud\Sync\NcHitToEntityMapperInterface;
use Waaseyaa\NorthCloud\Sync\NcSyncService;

/**
 * @covers \Waaseyaa\NorthCloud\Sync\NcSyncService
 */
#[CoversClass(NcSyncService::class)]
final class NcSyncServiceTest extends TestCase
{
    #[Test]
    public function fetchFailurePath(): void
    {
        $client = $this->stubClient(null);
        $service = new NcSyncService($client, $this->stubEntityTypeManager([]), new MapperRegistry());

        $result = $service->sync();

        $this->assertTrue($result->fetchFailed);
    }

    #[Test]
    public function responseMissingHitsKeyIsFetchFailed(): void
    {
        $client = $this->stubClient(['total_hits' => 0]); // no 'hits' key
        $service = new NcSyncService($client, $this->stubEntityTypeManager([]), new MapperRegistry());

        $result = $service->sync();

        $this->assertTrue($result->fetchFailed);
    }

    #[Test]
    public function emptyHitsYieldsZeroResults(): void
    {
        $client = $this->stubClient(['hits' => [], 'total_hits' => 0]);
        $service = new NcSyncService($client, $this->stubEntityTypeManager([]), new MapperRegistry());

        $result = $service->sync();

        $this->assertFalse($result->fetchFailed);
        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->skipped);
    }

    #[Test]
    public function malformedHitItemCountsAsFailedWithoutAbortingBatch(): void
    {
        $client = $this->stubClient([
            'hits' => ['bad-hit', ['id' => 'good']],
            'total_hits' => 2,
        ]);

        $entity = $this->createStub(EntityInterface::class);
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($this->stubQuery([]));
        $storage->expects($this->once())->method('create')->willReturn($entity);
        $storage->expects($this->once())->method('save')->willReturn(1);

        $registry = new MapperRegistry();
        $registry->register(new FakeMapper(dedup: 'source_url', map: ['source_url' => 'https://x']));

        $service = new NcSyncService(
            $client,
            $this->stubEntityTypeManager(['thing' => $storage]),
            $registry,
        );

        $result = $service->sync();

        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->created);
        $this->assertFalse($result->fetchFailed);
    }

    #[Test]
    public function mapperFailureBeforePersistenceCountsAsFailedWithoutAbortingBatch(): void
    {
        $client = $this->stubClient([
            'hits' => [['id' => 'bad'], ['id' => 'good']],
            'total_hits' => 2,
        ]);

        $entity = $this->createStub(EntityInterface::class);
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($this->stubQuery([]));
        $storage->expects($this->once())->method('create')->willReturn($entity);
        $storage->expects($this->once())->method('save')->willReturn(1);

        $registry = new MapperRegistry();
        $registry->register(new ThrowingMapper(throwOnId: 'bad'));
        $registry->register(new SelectiveMapper(
            supportedIds: ['good'],
            map: ['source_url' => 'https://x'],
        ));

        $service = new NcSyncService(
            $client,
            $this->stubEntityTypeManager(['thing' => $storage]),
            $registry,
        );

        $result = $service->sync();

        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->created);
        $this->assertFalse($result->fetchFailed);
    }

    #[Test]
    public function dedupHitExistingEntityIsSkipped(): void
    {
        $client = $this->stubClient(['hits' => [['id' => 'a']], 'total_hits' => 1]);

        $existingEntity = $this->createStub(EntityInterface::class);
        $query = $this->stubQuery([$existingEntity]);
        $storage = $this->stubStorage($query);

        $registry = new MapperRegistry();
        $registry->register(new FakeMapper(dedup: 'source_url', map: ['source_url' => 'https://x', 'title' => 'T']));

        $service = new NcSyncService(
            $client,
            $this->stubEntityTypeManager(['thing' => $storage]),
            $registry,
        );

        $result = $service->sync();

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->skipped);
    }

    #[Test]
    public function dedupQueryBindsAccessCheckFalse(): void
    {
        // Regression for B-5: the dedup-existence query must be bound with accessCheck(false).
        // Otherwise the real SqlEntityStorage (accessCheckEnabled defaults true, no account in
        // a background sync) throws MissingQueryAccountException and deduplication silently fails.
        $client = $this->stubClient(['hits' => [['id' => 'a']], 'total_hits' => 1]);

        $query = $this->createMock(EntityQueryInterface::class);
        $query->expects($this->once())->method('accessCheck')->with(false)->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('execute')->willReturn([$this->createStub(EntityInterface::class)]);

        $registry = new MapperRegistry();
        $registry->register(new FakeMapper(dedup: 'source_url', map: ['source_url' => 'https://x', 'title' => 'T']));

        $service = new NcSyncService(
            $client,
            $this->stubEntityTypeManager(['thing' => $this->stubStorage($query)]),
            $registry,
        );

        $result = $service->sync();

        $this->assertSame(1, $result->skipped);
    }

    #[Test]
    public function dedupFieldMissingFromMapThrowsLogicException(): void
    {
        $client = $this->stubClient(['hits' => [['id' => 'a']], 'total_hits' => 1]);

        $storage = $this->stubStorage($this->stubQuery([]));
        $registry = new MapperRegistry();
        $registry->register(new FakeMapper(dedup: 'missing_key', map: ['title' => 'T']));

        $service = new NcSyncService(
            $client,
            $this->stubEntityTypeManager(['thing' => $storage]),
            $registry,
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("dedupField 'missing_key'");
        $service->sync();
    }

    #[Test]
    public function emptyDedupFieldSkipsDedupCheck(): void
    {
        $client = $this->stubClient(['hits' => [['id' => 'a']], 'total_hits' => 1]);

        $entity = $this->createStub(EntityInterface::class);
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())->method('create')->willReturn($entity);
        $storage->expects($this->once())->method('save')->willReturn(1);
        $storage->expects($this->never())->method('getQuery');

        $registry = new MapperRegistry();
        $registry->register(new FakeMapper(dedup: '', map: ['title' => 'T']));

        $service = new NcSyncService(
            $client,
            $this->stubEntityTypeManager(['thing' => $storage]),
            $registry,
        );

        $result = $service->sync();

        $this->assertSame(1, $result->created);
    }

    #[Test]
    public function dryRunCountsCreatedWithoutPersisting(): void
    {
        $client = $this->stubClient(['hits' => [['id' => 'a']], 'total_hits' => 1]);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($this->stubQuery([]));
        $storage->expects($this->never())->method('create');
        $storage->expects($this->never())->method('save');

        $registry = new MapperRegistry();
        $registry->register(new FakeMapper(dedup: 'source_url', map: ['source_url' => 'https://x']));

        $service = new NcSyncService(
            $client,
            $this->stubEntityTypeManager(['thing' => $storage]),
            $registry,
        );

        $result = $service->sync(dryRun: true);

        $this->assertSame(1, $result->created);
    }

    #[Test]
    public function explainCapturesSkipReasonsAndSampleRows(): void
    {
        $client = $this->stubClient(['hits' => [['id' => 'a', 'title' => 'No region', 'url' => 'https://x']], 'total_hits' => 1]);

        $registry = new MapperRegistry();
        $registry->register(new DiagnosticRejectingMapper('missing_regional_signal'));

        $service = new NcSyncService(
            $client,
            $this->stubEntityTypeManager(['thing' => $this->stubStorage($this->stubQuery([]))]),
            $registry,
        );

        $result = $service->sync(dryRun: true, explain: true, sampleLimit: 5);

        $this->assertSame(1, $result->skipped);
        $this->assertSame(['missing_regional_signal' => 1], $result->skipReasons);
        $this->assertCount(1, $result->skippedSamples);
        $this->assertSame('missing_regional_signal', $result->skippedSamples[0]['reason'] ?? null);
        $this->assertSame(1, $result->fetched);
    }

    #[Test]
    public function mapperExceptionDuringSaveIsCaughtAndCountsAsFailed(): void
    {
        $client = $this->stubClient(['hits' => [['id' => 'a']], 'total_hits' => 1]);

        $entity = $this->createStub(EntityInterface::class);
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($this->stubQuery([]));
        $storage->method('create')->willReturn($entity);
        $storage->method('save')->willThrowException(new \Exception('boom'));

        $registry = new MapperRegistry();
        $registry->register(new FakeMapper(dedup: 'source_url', map: ['source_url' => 'https://x']));

        $service = new NcSyncService(
            $client,
            $this->stubEntityTypeManager(['thing' => $storage]),
            $registry,
        );

        // Silence error_log output during test
        $originalErrorLog = ini_get('error_log');
        ini_set('error_log', '/dev/null');
        try {
            $result = $service->sync();
        } finally {
            ini_set('error_log', $originalErrorLog === false ? '' : $originalErrorLog);
        }

        $this->assertSame(1, $result->failed);
        $this->assertSame(0, $result->created);
    }

    private function stubClient(?array $response): NorthCloudClient
    {
        return new NorthCloudClient(
            baseUrl: 'https://nc.test',
            httpClient: static fn(): string|false => $response === null ? false : (string) json_encode($response),
        );
    }

    /**
     * @param array<string, EntityStorageInterface> $storages
     */
    private function stubEntityTypeManager(array $storages): EntityTypeManager
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
     * @param list<EntityInterface> $results
     */
    private function stubQuery(array $results): EntityQueryInterface
    {
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('execute')->willReturn($results);
        return $query;
    }

    private function stubStorage(EntityQueryInterface $query): EntityStorageInterface
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);
        return $storage;
    }
}

/**
 * Minimal fake mapper for NcSyncServiceTest.
 */
final class FakeMapper implements NcHitToEntityMapperInterface
{
    /**
     * @param array<string, mixed> $map
     */
    public function __construct(
        private readonly string $dedup,
        private readonly array $map,
        private readonly string $entityType = 'thing',
        private readonly bool $supports = true,
    ) {}

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function supports(array $hit): bool
    {
        return $this->supports;
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

final class ThrowingMapper implements NcHitToEntityMapperInterface
{
    public function __construct(private readonly string $throwOnId) {}

    public function entityType(): string
    {
        return 'thing';
    }

    public function supports(array $hit): bool
    {
        return ($hit['id'] ?? null) === $this->throwOnId;
    }

    public function map(array $hit): array
    {
        if (($hit['id'] ?? null) === $this->throwOnId) {
            throw new \RuntimeException('mapper blew up');
        }

        return ['source_url' => 'https://mapped.example/' . (string) ($hit['id'] ?? 'unknown')];
    }

    public function dedupField(): string
    {
        return 'source_url';
    }
}

final class SelectiveMapper implements NcHitToEntityMapperInterface
{
    /**
     * @param list<string> $supportedIds
     * @param array<string, mixed> $map
     */
    public function __construct(
        private readonly array $supportedIds,
        private readonly array $map,
    ) {}

    public function entityType(): string
    {
        return 'thing';
    }

    public function supports(array $hit): bool
    {
        return in_array((string) ($hit['id'] ?? ''), $this->supportedIds, true);
    }

    public function map(array $hit): array
    {
        return $this->map;
    }

    public function dedupField(): string
    {
        return 'source_url';
    }
}

final class DiagnosticRejectingMapper implements NcHitToEntityMapperInterface, NcHitSupportDiagnosticsInterface
{
    public function __construct(private readonly string $reason) {}

    public function entityType(): string
    {
        return 'thing';
    }

    public function supports(array $hit): bool
    {
        return false;
    }

    public function diagnoseSupport(array $hit): array
    {
        return [
            'supported' => false,
            'reason' => $this->reason,
            'details' => ['id' => (string) ($hit['id'] ?? '')],
        ];
    }

    public function map(array $hit): array
    {
        return ['source_url' => 'https://mapped.example/never'];
    }

    public function dedupField(): string
    {
        return 'source_url';
    }
}
