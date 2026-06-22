<?php

declare(strict_types=1);

namespace Waaseyaa\Notification\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Notification\ChannelInterface;
use Waaseyaa\Notification\Job\SendNotificationHandler;
use Waaseyaa\Notification\Job\SendNotificationJob;
use Waaseyaa\Notification\NotifiableInterface;
use Waaseyaa\Notification\NotificationInterface;

#[CoversClass(SendNotificationHandler::class)]
final class SendNotificationHandlerTest extends TestCase
{
    #[Test]
    public function async_delivery_renders_channel_specific_methods_not_just_to_array(): void
    {
        $mail = new CapturingChannel();
        $database = new CapturingChannel();
        $handler = new SendNotificationHandler(['mail' => $mail, 'database' => $database]);

        $job = new SendNotificationJob(
            notifiableType: 'user',
            notifiableId: '7',
            notification: new ChannelAwareNotification(),
            channels: ['mail', 'database'],
        );

        $handler->handle($job);

        // The real notification — with toMail()/toDatabase() — must reach the
        // channels, not a flattened via()+toArray() stand-in that drops them.
        self::assertInstanceOf(NotificationInterface::class, $mail->notification);
        self::assertTrue(
            method_exists($mail->notification, 'toMail'),
            'Mail channel received a notification without toMail() — async dropped the mail rendering.',
        );
        self::assertTrue(method_exists($database->notification, 'toDatabase'));

        // And the channel-specific renderers produce their distinctive output
        // (not the generic toArray() payload).
        self::assertSame('MAIL: hello', $mail->notification->toMail($mail->notifiable));
        self::assertSame(['db' => 'hello'], $database->notification->toDatabase($database->notifiable));
    }

    #[Test]
    public function survives_a_queue_serialize_round_trip(): void
    {
        // The job is serialize()d onto the queue; the real notification must
        // come back intact so the worker can still render every channel.
        $job = new SendNotificationJob(
            notifiableType: 'user',
            notifiableId: '7',
            notification: new ChannelAwareNotification(),
            channels: ['mail'],
        );

        /** @var SendNotificationJob $revived */
        $revived = unserialize(serialize($job));

        $mail = new CapturingChannel();
        new SendNotificationHandler(['mail' => $mail])->handle($revived);

        self::assertTrue(method_exists($mail->notification, 'toMail'));
        self::assertSame('MAIL: hello', $mail->notification->toMail($mail->notifiable));
    }
}

final class CapturingChannel implements ChannelInterface
{
    public ?NotificationInterface $notification = null;
    public ?NotifiableInterface $notifiable = null;

    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        $this->notifiable = $notifiable;
        $this->notification = $notification;
    }
}

final class ChannelAwareNotification implements NotificationInterface
{
    public function __construct(private readonly string $message = 'hello') {}

    public function via(NotifiableInterface $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(NotifiableInterface $notifiable): array
    {
        return ['message' => $this->message];
    }

    public function toMail(NotifiableInterface $notifiable): string
    {
        return 'MAIL: ' . $this->message;
    }

    /** @return array<string, mixed> */
    public function toDatabase(NotifiableInterface $notifiable): array
    {
        return ['db' => $this->message];
    }
}
