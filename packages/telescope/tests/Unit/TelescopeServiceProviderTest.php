<?php

declare(strict_types=1);

namespace Aurora\Telescope\Tests\Unit;

use Aurora\Telescope\Recorder\CacheRecorder;
use Aurora\Telescope\Recorder\EventRecorder;
use Aurora\Telescope\Recorder\QueryRecorder;
use Aurora\Telescope\Recorder\RequestRecorder;
use Aurora\Telescope\Storage\SqliteTelescopeStore;
use Aurora\Telescope\Storage\TelescopeStoreInterface;
use Aurora\Telescope\TelescopeServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TelescopeServiceProvider::class)]
final class TelescopeServiceProviderTest extends TestCase
{
    #[Test]
    public function isEnabledByDefault(): void
    {
        $provider = new TelescopeServiceProvider();

        $this->assertTrue($provider->isEnabled());
    }

    #[Test]
    public function canBeDisabledViaConfig(): void
    {
        $provider = new TelescopeServiceProvider(config: ['enabled' => false]);

        $this->assertFalse($provider->isEnabled());
    }

    #[Test]
    public function providesStore(): void
    {
        $provider = new TelescopeServiceProvider();

        $this->assertInstanceOf(TelescopeStoreInterface::class, $provider->getStore());
    }

    #[Test]
    public function acceptsCustomStore(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $provider = new TelescopeServiceProvider(store: $store);

        $this->assertSame($store, $provider->getStore());
    }

    #[Test]
    public function providesQueryRecorderWhenEnabled(): void
    {
        $provider = new TelescopeServiceProvider();

        $recorder = $provider->getQueryRecorder();
        $this->assertInstanceOf(QueryRecorder::class, $recorder);
    }

    #[Test]
    public function returnsNullQueryRecorderWhenDisabled(): void
    {
        $provider = new TelescopeServiceProvider(config: ['enabled' => false]);

        $this->assertNull($provider->getQueryRecorder());
    }

    #[Test]
    public function returnsNullQueryRecorderWhenQueriesRecordingDisabled(): void
    {
        $provider = new TelescopeServiceProvider(config: [
            'record' => ['queries' => false],
        ]);

        $this->assertNull($provider->getQueryRecorder());
    }

    #[Test]
    public function providesEventRecorderWhenEnabled(): void
    {
        $provider = new TelescopeServiceProvider();

        $recorder = $provider->getEventRecorder();
        $this->assertInstanceOf(EventRecorder::class, $recorder);
    }

    #[Test]
    public function returnsNullEventRecorderWhenDisabled(): void
    {
        $provider = new TelescopeServiceProvider(config: ['enabled' => false]);

        $this->assertNull($provider->getEventRecorder());
    }

    #[Test]
    public function returnsNullEventRecorderWhenEventsRecordingDisabled(): void
    {
        $provider = new TelescopeServiceProvider(config: [
            'record' => ['events' => false],
        ]);

        $this->assertNull($provider->getEventRecorder());
    }

    #[Test]
    public function providesRequestRecorderWhenEnabled(): void
    {
        $provider = new TelescopeServiceProvider();

        $recorder = $provider->getRequestRecorder();
        $this->assertInstanceOf(RequestRecorder::class, $recorder);
    }

    #[Test]
    public function returnsNullRequestRecorderWhenDisabled(): void
    {
        $provider = new TelescopeServiceProvider(config: ['enabled' => false]);

        $this->assertNull($provider->getRequestRecorder());
    }

    #[Test]
    public function returnsNullRequestRecorderWhenRequestsRecordingDisabled(): void
    {
        $provider = new TelescopeServiceProvider(config: [
            'record' => ['requests' => false],
        ]);

        $this->assertNull($provider->getRequestRecorder());
    }

    #[Test]
    public function providesCacheRecorderWhenEnabled(): void
    {
        $provider = new TelescopeServiceProvider();

        $recorder = $provider->getCacheRecorder();
        $this->assertInstanceOf(CacheRecorder::class, $recorder);
    }

    #[Test]
    public function returnsNullCacheRecorderWhenDisabled(): void
    {
        $provider = new TelescopeServiceProvider(config: ['enabled' => false]);

        $this->assertNull($provider->getCacheRecorder());
    }

    #[Test]
    public function returnsNullCacheRecorderWhenCacheRecordingDisabled(): void
    {
        $provider = new TelescopeServiceProvider(config: [
            'record' => ['cache' => false],
        ]);

        $this->assertNull($provider->getCacheRecorder());
    }

    #[Test]
    public function queryRecorderRespectsSlowQueryConfig(): void
    {
        $provider = new TelescopeServiceProvider(config: [
            'record' => [
                'slow_query_threshold' => 200.0,
                'slow_queries_only' => true,
            ],
        ]);

        $recorder = $provider->getQueryRecorder();
        $this->assertNotNull($recorder);
        $this->assertSame(200.0, $recorder->getSlowQueryThreshold());
        $this->assertTrue($recorder->isSlowQueriesOnly());
    }

    #[Test]
    public function requestRecorderRespectsIgnorePathsConfig(): void
    {
        $provider = new TelescopeServiceProvider(config: [
            'ignore_paths' => ['/health', '/api/broadcast/*'],
        ]);

        $recorder = $provider->getRequestRecorder();
        $this->assertNotNull($recorder);
        $this->assertSame(['/health', '/api/broadcast/*'], $recorder->getIgnorePaths());
    }

    #[Test]
    public function returnsSameRecorderInstanceOnMultipleCalls(): void
    {
        $provider = new TelescopeServiceProvider();

        $query1 = $provider->getQueryRecorder();
        $query2 = $provider->getQueryRecorder();
        $this->assertSame($query1, $query2);

        $event1 = $provider->getEventRecorder();
        $event2 = $provider->getEventRecorder();
        $this->assertSame($event1, $event2);

        $request1 = $provider->getRequestRecorder();
        $request2 = $provider->getRequestRecorder();
        $this->assertSame($request1, $request2);

        $cache1 = $provider->getCacheRecorder();
        $cache2 = $provider->getCacheRecorder();
        $this->assertSame($cache1, $cache2);
    }
}
