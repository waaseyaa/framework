<?php

declare(strict_types=1);

namespace Aurora\Telescope\Tests\Unit\Recorder;

use Aurora\Telescope\Recorder\RequestRecorder;
use Aurora\Telescope\Storage\SqliteTelescopeStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestRecorder::class)]
final class RequestRecorderTest extends TestCase
{
    private SqliteTelescopeStore $store;

    protected function setUp(): void
    {
        $this->store = SqliteTelescopeStore::createInMemory();
    }

    #[Test]
    public function recordsRequestWithAllFields(): void
    {
        $recorder = new RequestRecorder(store: $this->store);

        $recorder->record(
            method: 'POST',
            uri: '/api/nodes',
            statusCode: 201,
            duration: 45.7,
            controller: 'NodeController::create',
            middleware: ['auth', 'json'],
        );

        $entries = $this->store->query(RequestRecorder::TYPE);
        $this->assertCount(1, $entries);

        $data = $entries[0]->data;
        $this->assertSame('POST', $data['method']);
        $this->assertSame('/api/nodes', $data['uri']);
        $this->assertSame(201, $data['status_code']);
        $this->assertSame(45.7, $data['duration']);
        $this->assertSame('NodeController::create', $data['controller']);
        $this->assertSame(['auth', 'json'], $data['middleware']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    #[Test]
    public function recordsRequestWithDefaults(): void
    {
        $recorder = new RequestRecorder(store: $this->store);

        $recorder->record(
            method: 'GET',
            uri: '/',
            statusCode: 200,
            duration: 10.0,
        );

        $entries = $this->store->query(RequestRecorder::TYPE);
        $data = $entries[0]->data;

        $this->assertSame('', $data['controller']);
        $this->assertSame([], $data['middleware']);
    }

    #[Test]
    public function ignoresPathsMatchingPatterns(): void
    {
        $recorder = new RequestRecorder(
            store: $this->store,
            ignorePaths: ['/health', '/api/broadcast/*'],
        );

        $recorder->record(method: 'GET', uri: '/health', statusCode: 200, duration: 1.0);
        $recorder->record(method: 'GET', uri: '/api/broadcast/channel1', statusCode: 200, duration: 1.0);
        $recorder->record(method: 'GET', uri: '/api/nodes', statusCode: 200, duration: 1.0);

        $entries = $this->store->query(RequestRecorder::TYPE);
        $this->assertCount(1, $entries);
        $this->assertSame('/api/nodes', $entries[0]->data['uri']);
    }

    #[Test]
    public function exposesIgnorePaths(): void
    {
        $recorder = new RequestRecorder(
            store: $this->store,
            ignorePaths: ['/health', '/status'],
        );

        $this->assertSame(['/health', '/status'], $recorder->getIgnorePaths());
    }

    #[Test]
    public function typeConstantIsRequest(): void
    {
        $this->assertSame('request', RequestRecorder::TYPE);
    }
}
