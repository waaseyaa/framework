<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseBroadcasting;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\EventListenerRegistrar;
use Waaseyaa\User\User;

/**
 * End-to-end coverage of the broadcasting pipeline:
 * EventDispatcher → EventListenerRegistrar::registerBroadcastListeners →
 * BroadcastStorage::push → BroadcastStorage::poll.
 *
 * Exercises the production wiring without spinning up HttpKernel: the listener
 * registrar is the same instance the kernel constructs, and the storage is a
 * real DBALDatabase-backed SQLite memory connection.
 */
#[CoversNothing]
final class BroadcastingE2ETest extends TestCase
{
    private DBALDatabase $database;
    private BroadcastStorage $storage;
    private SymfonyEventDispatcherAdapter $dispatcher;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->storage = new BroadcastStorage($this->database);
        $this->dispatcher = new SymfonyEventDispatcherAdapter();
        $registrar = new EventListenerRegistrar($this->dispatcher);
        $registrar->registerBroadcastListeners($this->storage);
    }

    #[Test]
    public function entity_post_save_pushes_to_admin_channel(): void
    {
        $user = User::make(['uid' => 7, 'uuid' => 'user-7-uuid', 'name' => 'alice', 'mail' => 'a@example.com']);

        $this->dispatcher->dispatch(new EntityEvent($user), EntityEvents::POST_SAVE->value);

        $messages = $this->storage->poll(0);
        self::assertCount(1, $messages);
        self::assertSame('admin', $messages[0]['channel']);
        self::assertSame('entity.saved', $messages[0]['event']);
        self::assertSame('user-7-uuid', $messages[0]['data']['id']);
        self::assertSame('user', $messages[0]['data']['entityType']);
    }

    #[Test]
    public function entity_post_delete_pushes_to_admin_channel(): void
    {
        $user = User::make(['uid' => 9, 'uuid' => 'user-9-uuid', 'name' => 'bob', 'mail' => 'b@example.com']);

        $this->dispatcher->dispatch(new EntityEvent($user), EntityEvents::POST_DELETE->value);

        $messages = $this->storage->poll(0);
        self::assertCount(1, $messages);
        self::assertSame('entity.deleted', $messages[0]['event']);
        self::assertSame('user-9-uuid', $messages[0]['data']['id']);
    }

    #[Test]
    public function poll_respects_cursor(): void
    {
        $u1 = User::make(['uid' => 1, 'uuid' => 'u1', 'name' => 'a', 'mail' => 'a@example.com']);
        $u2 = User::make(['uid' => 2, 'uuid' => 'u2', 'name' => 'b', 'mail' => 'b@example.com']);
        $u3 = User::make(['uid' => 3, 'uuid' => 'u3', 'name' => 'c', 'mail' => 'c@example.com']);

        $this->dispatcher->dispatch(new EntityEvent($u1), EntityEvents::POST_SAVE->value);
        $this->dispatcher->dispatch(new EntityEvent($u2), EntityEvents::POST_SAVE->value);
        $firstBatch = $this->storage->poll(0);
        self::assertCount(2, $firstBatch);

        $cursor = $firstBatch[1]['id'];
        $this->dispatcher->dispatch(new EntityEvent($u3), EntityEvents::POST_SAVE->value);

        $secondBatch = $this->storage->poll($cursor);
        self::assertCount(1, $secondBatch);
        self::assertSame('u3', $secondBatch[0]['data']['id']);
    }

    #[Test]
    public function poll_filters_by_channel(): void
    {
        $user = User::make(['uid' => 5, 'uuid' => 'u5', 'name' => 'e', 'mail' => 'e@example.com']);

        $this->dispatcher->dispatch(new EntityEvent($user), EntityEvents::POST_SAVE->value);
        $this->storage->push('private', 'custom.event', ['payload' => 'secret']);

        $adminOnly = $this->storage->poll(0, ['admin']);
        $privateOnly = $this->storage->poll(0, ['private']);

        self::assertCount(1, $adminOnly);
        self::assertSame('admin', $adminOnly[0]['channel']);
        self::assertCount(1, $privateOnly);
        self::assertSame('private', $privateOnly[0]['channel']);
    }

    #[Test]
    public function prune_drops_old_rows(): void
    {
        $user = User::make(['uid' => 11, 'uuid' => 'u11', 'name' => 'p', 'mail' => 'p@example.com']);
        $this->dispatcher->dispatch(new EntityEvent($user), EntityEvents::POST_SAVE->value);

        // prune everything older than now+1s — should drop the row.
        $this->storage->prune(-1);

        self::assertSame([], $this->storage->poll(0));
    }
}
