<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;

#[CoversClass(BroadcastStorage::class)]
final class BroadcastStorageTest extends TestCase
{
    private DBALDatabase $database;
    private BroadcastStorage $storage;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->storage = new BroadcastStorage($this->database);
    }

    #[Test]
    public function pushAndPollReturnsMessages(): void
    {
        $this->storage->push('admin', 'entity.saved', ['type' => 'node', 'id' => '1']);
        $this->storage->push('admin', 'entity.deleted', ['type' => 'node', 'id' => '2']);

        $messages = $this->storage->poll(0);

        $this->assertCount(2, $messages);
        $this->assertSame('entity.saved', $messages[0]['event']);
        $this->assertSame('entity.deleted', $messages[1]['event']);
    }

    #[Test]
    public function pollWithCursorSkipsOlderMessages(): void
    {
        $this->storage->push('admin', 'first', []);
        $messages = $this->storage->poll(0);
        $cursor = $messages[0]['id'];

        $this->storage->push('admin', 'second', []);
        $messages = $this->storage->poll($cursor);

        $this->assertCount(1, $messages);
        $this->assertSame('second', $messages[0]['event']);
    }

    #[Test]
    public function pollFiltersByChannels(): void
    {
        $this->storage->push('admin', 'event1', []);
        $this->storage->push('system', 'event2', []);
        $this->storage->push('pipeline', 'event3', []);

        $messages = $this->storage->poll(0, ['admin', 'pipeline']);

        $this->assertCount(2, $messages);
        $this->assertSame('event1', $messages[0]['event']);
        $this->assertSame('event3', $messages[1]['event']);
    }

    #[Test]
    public function pollWithEmptyChannelsReturnsAll(): void
    {
        $this->storage->push('admin', 'event1', []);
        $this->storage->push('system', 'event2', []);

        $messages = $this->storage->poll(0, []);
        $this->assertCount(2, $messages);
    }

    #[Test]
    public function pushAndPollPreservesDataRoundTrip(): void
    {
        $this->storage->push('admin', 'entity.saved', ['type' => 'node', 'id' => '1']);

        $messages = $this->storage->poll(0);

        $this->assertCount(1, $messages);
        $this->assertSame(['type' => 'node', 'id' => '1'], $messages[0]['data']);
        $this->assertSame('admin', $messages[0]['channel']);
        $this->assertIsFloat($messages[0]['created_at']);
        $this->assertIsInt($messages[0]['id']);
    }

    #[Test]
    public function maxIdReturnsZeroOnEmptyLog(): void
    {
        $this->assertSame(0, $this->storage->maxId());
        $this->assertSame(0, $this->storage->maxId(['admin']));
    }

    #[Test]
    public function maxIdReturnsHighestRowId(): void
    {
        $this->storage->push('admin', 'a', []);
        $this->storage->push('admin', 'b', []);
        $this->storage->push('admin', 'c', []);

        $messages = $this->storage->poll(0);
        $expected = $messages[count($messages) - 1]['id'];

        $this->assertSame($expected, $this->storage->maxId());
    }

    #[Test]
    public function maxIdFiltersByChannels(): void
    {
        $this->storage->push('admin', 'a', []);   // id 1
        $this->storage->push('system', 'b', []);  // id 2
        $this->storage->push('admin', 'c', []);   // id 3

        $this->assertSame(3, $this->storage->maxId(['admin']));
        $this->assertSame(2, $this->storage->maxId(['system']));
        $this->assertSame(0, $this->storage->maxId(['nonexistent']));
        $this->assertSame(3, $this->storage->maxId());
    }

    #[Test]
    public function pruneRemovesOldMessages(): void
    {
        $this->storage->push('admin', 'old', []);
        usleep(10_000); // Ensure the message timestamp is strictly in the past.
        $this->storage->prune(0); // prune everything older than 0 seconds

        $messages = $this->storage->poll(0);
        $this->assertCount(0, $messages);
    }

    #[Test]
    public function prunePreservesRecentMessages(): void
    {
        $this->storage->push('admin', 'recent', []);
        $this->storage->prune(60); // prune messages older than 60 seconds

        $messages = $this->storage->poll(0);
        $this->assertCount(1, $messages);
        $this->assertSame('recent', $messages[0]['event']);
    }

    // ── Retained messages (Wayfinding beacon-reconnect race fix) ─────────────

    #[Test]
    public function pushRetainedDeliversLiveAndIsReplayableOnConnect(): void
    {
        $id = $this->storage->pushRetained(
            'session:abc',
            'wayfinding.beacon',
            ['anchor_id' => 'list:story', 'content' => 'hi', 'order' => 0],
            'list:story',
        );

        // Live: a currently-connected poller receives it from the log.
        $live = $this->storage->poll(0, ['session:abc']);
        $this->assertCount(1, $live);
        $this->assertSame('wayfinding.beacon', $live[0]['event']);
        $this->assertSame($id, $live[0]['id']);

        // Durable: a NEW subscriber replays it on connect — the reconnect race fix.
        $retained = $this->storage->retainedFor(['session:abc']);
        $this->assertCount(1, $retained);
        $this->assertSame('list:story', $retained[0]['data']['anchor_id']);
        $this->assertSame($id, $retained[0]['id'], 'replay carries the original broadcast id so clients de-dupe against the live push');
    }

    #[Test]
    public function retainedIsLastWriteWinsPerKey(): void
    {
        $this->storage->pushRetained('session:abc', 'wayfinding.beacon', ['anchor_id' => 'list:story', 'content' => 'first', 'order' => 0], 'list:story');
        $this->storage->pushRetained('session:abc', 'wayfinding.beacon', ['anchor_id' => 'list:story', 'content' => 'second', 'order' => 0], 'list:story');

        $retained = $this->storage->retainedFor(['session:abc']);
        $this->assertCount(1, $retained, 're-emitting the same key supersedes the prior value');
        $this->assertSame('second', $retained[0]['data']['content']);
    }

    #[Test]
    public function retainedForIsChannelScopedAndOrderedByEmission(): void
    {
        $this->storage->pushRetained('session:abc', 'wayfinding.beacon', ['anchor_id' => 'list:story', 'content' => 'a', 'order' => 0], 'list:story');
        $this->storage->pushRetained('session:abc', 'wayfinding.beacon', ['anchor_id' => 'action:story:edit', 'content' => 'b', 'order' => 1], 'action:story:edit');
        // A different session's retained beacon is never returned (LD-1 isolation).
        $this->storage->pushRetained('session:other', 'wayfinding.beacon', ['anchor_id' => 'list:story', 'content' => 'x', 'order' => 0], 'list:story');

        $retained = $this->storage->retainedFor(['session:abc']);
        $this->assertSame(
            ['list:story', 'action:story:edit'],
            array_map(static fn(array $m): string => $m['data']['anchor_id'], $retained),
        );
    }

    #[Test]
    public function dropRetainedClearsOneKeyOrTheWholeChannel(): void
    {
        $this->storage->pushRetained('session:abc', 'wayfinding.beacon', ['anchor_id' => 'list:story', 'content' => 'a', 'order' => 0], 'list:story');
        $this->storage->pushRetained('session:abc', 'wayfinding.beacon', ['anchor_id' => 'action:story:edit', 'content' => 'b', 'order' => 1], 'action:story:edit');

        $this->storage->dropRetained('session:abc', 'list:story');
        $this->assertCount(1, $this->storage->retainedFor(['session:abc']), 'keyed drop removes only that beacon');

        // Whole-channel drop is what a viewer's dismiss triggers.
        $this->storage->dropRetained('session:abc');
        $this->assertCount(0, $this->storage->retainedFor(['session:abc']));
    }

    #[Test]
    public function expiredRetainedMessagesAreNotReplayedAndArePruned(): void
    {
        // A TTL already in the past — the beacon is "active (non-expired)" no more.
        $this->storage->pushRetained('session:abc', 'wayfinding.beacon', ['anchor_id' => 'list:story', 'content' => 'a', 'order' => 0], 'list:story', -1.0);

        $this->assertCount(0, $this->storage->retainedFor(['session:abc']));
    }
}
