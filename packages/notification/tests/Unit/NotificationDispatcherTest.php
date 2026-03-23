<?php

declare(strict_types=1);

namespace Waaseyaa\Notification\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\Transport\ArrayTransport;
use Waaseyaa\Mail\Mailer;
use Waaseyaa\Notification\Channel\DatabaseChannel;
use Waaseyaa\Notification\Channel\MailChannel;
use Waaseyaa\Notification\NotifiableInterface;
use Waaseyaa\Notification\NotificationDispatcher;
use Waaseyaa\Notification\NotificationInterface;
use Waaseyaa\Queue\SyncQueue;
use Waaseyaa\Database\DBALDatabase;

#[CoversClass(NotificationDispatcher::class)]
#[CoversClass(MailChannel::class)]
#[CoversClass(DatabaseChannel::class)]
final class NotificationDispatcherTest extends TestCase
{
    #[Test]
    public function sendsViaMailChannel(): void
    {
        $transport = new ArrayTransport();
        $mailer = new Mailer($transport, 'noreply@example.com');
        $channels = ['mail' => new MailChannel($mailer)];

        $dispatcher = new NotificationDispatcher(new SyncQueue(), $channels);

        $notifiable = $this->createNotifiable('user', '1', 'user@example.com');
        $notification = $this->createMailNotification();

        $dispatcher->send($notifiable, $notification);

        self::assertCount(1, $transport->getSent());
    }

    #[Test]
    public function sendsViaDatabaseChannel(): void
    {
        $db = DBALDatabase::createSqlite();
        $this->createNotificationsTable($db);

        $channels = ['database' => new DatabaseChannel($db)];
        $dispatcher = new NotificationDispatcher(new SyncQueue(), $channels);

        $notifiable = $this->createNotifiable('user', '42', 'user@example.com');
        $notification = $this->createDatabaseNotification();

        $dispatcher->send($notifiable, $notification);

        $rows = iterator_to_array(
            $db->select('waaseyaa_notifications', 'n')
                ->fields('n', ['notifiable_id', 'notifiable_type', 'data'])
                ->execute()
        );

        self::assertCount(1, $rows);
        self::assertSame('42', $rows[0]['notifiable_id']);
        self::assertSame('user', $rows[0]['notifiable_type']);
    }

    #[Test]
    public function sendsToMultipleChannels(): void
    {
        $transport = new ArrayTransport();
        $mailer = new Mailer($transport, 'noreply@example.com');
        $db = DBALDatabase::createSqlite();
        $this->createNotificationsTable($db);

        $channels = [
            'mail' => new MailChannel($mailer),
            'database' => new DatabaseChannel($db),
        ];

        $dispatcher = new NotificationDispatcher(new SyncQueue(), $channels);

        $notifiable = $this->createNotifiable('user', '1', 'user@example.com');
        $notification = $this->createMultiChannelNotification();

        $dispatcher->send($notifiable, $notification);

        self::assertCount(1, $transport->getSent());

        $rows = iterator_to_array(
            $db->select('waaseyaa_notifications', 'n')
                ->fields('n', ['id'])
                ->execute()
        );
        self::assertCount(1, $rows);
    }

    #[Test]
    public function skipsUnknownChannels(): void
    {
        $dispatcher = new NotificationDispatcher(new SyncQueue(), []);

        $notifiable = $this->createNotifiable('user', '1', 'user@example.com');
        $notification = $this->createMailNotification();

        // Should not throw
        $dispatcher->send($notifiable, $notification);

        self::assertTrue(true); // No exception means success
    }

    #[Test]
    public function handlesChannelFailureGracefully(): void
    {
        $failingChannel = new class implements \Waaseyaa\Notification\ChannelInterface {
            public function send(
                \Waaseyaa\Notification\NotifiableInterface $notifiable,
                \Waaseyaa\Notification\NotificationInterface $notification,
            ): void {
                throw new \RuntimeException('Channel failed');
            }
        };

        $dispatcher = new NotificationDispatcher(new SyncQueue(), ['mail' => $failingChannel]);

        $notifiable = $this->createNotifiable('user', '1', 'user@example.com');
        $notification = $this->createMailNotification();

        // Should not throw — best-effort delivery
        $dispatcher->send($notifiable, $notification);

        self::assertTrue(true);
    }

    private function createNotifiable(string $type, string $id, string $email): NotifiableInterface
    {
        return new class($type, $id, $email) implements NotifiableInterface {
            public function __construct(
                private readonly string $type,
                private readonly string $id,
                private readonly string $email,
            ) {}

            public function routeNotificationFor(string $channel): mixed
            {
                return match ($channel) {
                    'mail' => $this->email,
                    'database' => $this->id,
                    default => null,
                };
            }

            public function getNotifiableId(): string
            {
                return $this->id;
            }

            public function getNotifiableType(): string
            {
                return $this->type;
            }
        };
    }

    private function createMailNotification(): NotificationInterface
    {
        return new class implements NotificationInterface {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['mail'];
            }

            public function toArray(NotifiableInterface $notifiable): array
            {
                return ['message' => 'Test'];
            }

            public function toMail(NotifiableInterface $notifiable): Envelope
            {
                return new Envelope(
                    to: [$notifiable->routeNotificationFor('mail')],
                    from: 'noreply@example.com',
                    subject: 'Test Notification',
                    textBody: 'This is a test notification.',
                );
            }
        };
    }

    private function createDatabaseNotification(): NotificationInterface
    {
        return new class implements NotificationInterface {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['database'];
            }

            public function toArray(NotifiableInterface $notifiable): array
            {
                return ['message' => 'Stored notification'];
            }
        };
    }

    private function createMultiChannelNotification(): NotificationInterface
    {
        return new class implements NotificationInterface {
            public function via(NotifiableInterface $notifiable): array
            {
                return ['mail', 'database'];
            }

            public function toArray(NotifiableInterface $notifiable): array
            {
                return ['message' => 'Multi-channel'];
            }

            public function toMail(NotifiableInterface $notifiable): Envelope
            {
                return new Envelope(
                    to: [$notifiable->routeNotificationFor('mail')],
                    from: 'noreply@example.com',
                    subject: 'Multi-channel Notification',
                    textBody: 'Sent to both mail and database.',
                );
            }
        };
    }

    private function createNotificationsTable(DBALDatabase $db): void
    {
        $db->schema()->createTable('waaseyaa_notifications', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'type' => ['type' => 'varchar', 'not null' => true],
                'notifiable_type' => ['type' => 'varchar', 'not null' => true],
                'notifiable_id' => ['type' => 'varchar', 'not null' => true],
                'data' => ['type' => 'text', 'not null' => true],
                'created_at' => ['type' => 'varchar', 'length' => 50, 'not null' => true],
                'read_at' => ['type' => 'varchar', 'length' => 50],
            ],
            'primary key' => ['id'],
        ]);
    }
}
