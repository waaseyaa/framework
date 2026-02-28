<?php

declare(strict_types=1);

namespace Aurora\Telescope\Tests\Unit\Recorder;

use Aurora\Telescope\Recorder\CacheRecorder;
use Aurora\Telescope\Storage\SqliteTelescopeStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheRecorder::class)]
final class CacheRecorderTest extends TestCase
{
    private SqliteTelescopeStore $store;
    private CacheRecorder $recorder;

    protected function setUp(): void
    {
        $this->store = SqliteTelescopeStore::createInMemory();
        $this->recorder = new CacheRecorder(store: $this->store);
    }

    #[Test]
    public function recordsCacheOperation(): void
    {
        $this->recorder->record(
            operation: 'hit',
            key: 'users:1',
            value: ['id' => 1, 'name' => 'Admin'],
            duration: 0.5,
        );

        $entries = $this->store->query(CacheRecorder::TYPE);
        $this->assertCount(1, $entries);

        $data = $entries[0]->data;
        $this->assertSame('hit', $data['operation']);
        $this->assertSame('users:1', $data['key']);
        $this->assertSame(['id' => 1, 'name' => 'Admin'], $data['value']);
        $this->assertSame(0.5, $data['duration']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    #[Test]
    public function recordHitConvenience(): void
    {
        $this->recorder->recordHit('config:site', 'Aurora CMS', 0.2);

        $entries = $this->store->query(CacheRecorder::TYPE);
        $data = $entries[0]->data;

        $this->assertSame('hit', $data['operation']);
        $this->assertSame('config:site', $data['key']);
        $this->assertSame('Aurora CMS', $data['value']);
    }

    #[Test]
    public function recordMissConvenience(): void
    {
        $this->recorder->recordMiss('config:missing', 1.0);

        $entries = $this->store->query(CacheRecorder::TYPE);
        $data = $entries[0]->data;

        $this->assertSame('miss', $data['operation']);
        $this->assertSame('config:missing', $data['key']);
        $this->assertArrayNotHasKey('value', $data);
    }

    #[Test]
    public function recordSetConvenience(): void
    {
        $this->recorder->recordSet('users:2', ['id' => 2], 3.0);

        $entries = $this->store->query(CacheRecorder::TYPE);
        $data = $entries[0]->data;

        $this->assertSame('set', $data['operation']);
        $this->assertSame('users:2', $data['key']);
        $this->assertSame(['id' => 2], $data['value']);
    }

    #[Test]
    public function recordForgetConvenience(): void
    {
        $this->recorder->recordForget('users:3');

        $entries = $this->store->query(CacheRecorder::TYPE);
        $data = $entries[0]->data;

        $this->assertSame('forget', $data['operation']);
        $this->assertSame('users:3', $data['key']);
    }

    #[Test]
    public function serializesObjectValueToClassName(): void
    {
        $this->recorder->record(
            operation: 'set',
            key: 'object:1',
            value: new \stdClass(),
        );

        $entries = $this->store->query(CacheRecorder::TYPE);
        $this->assertSame('stdClass(...)', $entries[0]->data['value']);
    }

    #[Test]
    public function omitsValueWhenNull(): void
    {
        $this->recorder->record(operation: 'miss', key: 'missing:key');

        $entries = $this->store->query(CacheRecorder::TYPE);
        $this->assertArrayNotHasKey('value', $entries[0]->data);
    }

    #[Test]
    public function typeConstantIsCache(): void
    {
        $this->assertSame('cache', CacheRecorder::TYPE);
    }
}
