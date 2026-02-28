<?php

declare(strict_types=1);

namespace Aurora\Telescope\Tests\Unit\Recorder;

use Aurora\Telescope\Recorder\EventRecorder;
use Aurora\Telescope\Storage\SqliteTelescopeStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventRecorder::class)]
final class EventRecorderTest extends TestCase
{
    private SqliteTelescopeStore $store;
    private EventRecorder $recorder;

    protected function setUp(): void
    {
        $this->store = SqliteTelescopeStore::createInMemory();
        $this->recorder = new EventRecorder(store: $this->store);
    }

    #[Test]
    public function recordsEventWithAllFields(): void
    {
        $this->recorder->record(
            eventClass: 'App\\Events\\UserCreated',
            payload: ['user_id' => 1, 'email' => 'test@example.com'],
            listeners: ['App\\Listeners\\SendWelcomeEmail', 'App\\Listeners\\LogRegistration'],
        );

        $entries = $this->store->query(EventRecorder::TYPE);
        $this->assertCount(1, $entries);

        $data = $entries[0]->data;
        $this->assertSame('App\\Events\\UserCreated', $data['event']);
        $this->assertSame(['user_id' => 1, 'email' => 'test@example.com'], $data['payload']);
        $this->assertSame(
            ['App\\Listeners\\SendWelcomeEmail', 'App\\Listeners\\LogRegistration'],
            $data['listeners']
        );
        $this->assertArrayHasKey('timestamp', $data);
    }

    #[Test]
    public function recordsEventWithEmptyListeners(): void
    {
        $this->recorder->record(
            eventClass: 'App\\Events\\Ping',
            payload: [],
        );

        $entries = $this->store->query(EventRecorder::TYPE);
        $this->assertSame([], $entries[0]->data['listeners']);
    }

    #[Test]
    public function recordsMultipleEvents(): void
    {
        $this->recorder->record(eventClass: 'Event1', payload: []);
        $this->recorder->record(eventClass: 'Event2', payload: []);
        $this->recorder->record(eventClass: 'Event3', payload: []);

        $entries = $this->store->query(EventRecorder::TYPE);
        $this->assertCount(3, $entries);
    }

    #[Test]
    public function typeConstantIsEvent(): void
    {
        $this->assertSame('event', EventRecorder::TYPE);
    }
}
