# waaseyaa/notification

**Layer 3 — Services**

Multi-channel notification system for Waaseyaa. A `NotificationInterface` declares which channels it targets (`via()`) and how it renders for each (`toMail()`, `toDatabase()`, …); a `NotifiableInterface` recipient supplies its per-channel routing (email address, database id). `NotificationDispatcher` fans a notification out across the matching `ChannelInterface` implementations — synchronously, or queued via the framework `Job` system so user-facing requests never wait on email or persistence. Channel delivery is best-effort: a single channel throwing is logged, not propagated.

## Install

Ships as part of `waaseyaa/framework` — consumers on the `core`/`cms`/`full` metapackages already have it. To pull it on its own:

```bash
composer require waaseyaa/notification
```

The package self-registers `NotificationServiceProvider` (via `extra.waaseyaa.providers`), which binds `NotificationDispatcher` as a singleton and assembles the `mail` and `database` channels from whatever `MailerInterface` / `DatabaseInterface` are configured.

## Key API

```php
interface NotificationInterface
{
    /** @return list<string> channel names, e.g. ['mail', 'database'] */
    public function via(NotifiableInterface $notifiable): array;
    /** @return array<string, mixed> */
    public function toArray(NotifiableInterface $notifiable): array;
    // Optional, resolved by channels via method_exists():
    //   toMail(NotifiableInterface): \Waaseyaa\Mail\Envelope
    //   toDatabase(NotifiableInterface): array<string, mixed>
}

interface NotifiableInterface          // @api
{
    public function routeNotificationFor(string $channel): mixed; // 'mail' => email, 'database' => id
    public function getNotifiableId(): string;
    public function getNotifiableType(): string;
}

interface ChannelInterface
{
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void;
}

final class NotificationDispatcher     // @api
{
    /** @param array<string, ChannelInterface> $channels */
    public function __construct(QueueInterface $queue, array $channels, ?LoggerInterface $logger = null);
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void;       // sync
    public function sendAsync(NotifiableInterface $notifiable, NotificationInterface $notification): void;  // queued
    /** @param iterable<NotifiableInterface> $notifiables */
    public function sendToMany(iterable $notifiables, NotificationInterface $notification): void;
    /** @return array<string, ChannelInterface> */
    public function channels(): array;
}
```

Helpers: `NotifiableTrait` (`@api`) implements `NotifiableInterface` for any class exposing `get()`/`id()`/`getEntityTypeId()` (e.g. `EntityBase` subclasses); `DefaultNotifiable` is a standalone implementation for apps and tests. Built-in channels: `Channel\MailChannel`, `Channel\DatabaseChannel` (persists to `waaseyaa_notifications`). Async delivery uses `Job\SendNotificationJob`.

## Usage

```php
use Waaseyaa\Notification\{NotifiableInterface, NotificationInterface, NotificationDispatcher};
use Waaseyaa\Notification\Channel\MailChannel;
use Waaseyaa\Mail\{Envelope, Mailer};
use Waaseyaa\Queue\SyncQueue;

$notification = new class implements NotificationInterface {
    public function via(NotifiableInterface $n): array { return ['mail']; }
    public function toArray(NotifiableInterface $n): array { return ['message' => 'Welcome']; }
    public function toMail(NotifiableInterface $n): Envelope {
        return new Envelope(
            to: [$n->routeNotificationFor('mail')],
            from: 'noreply@example.com',
            subject: 'Welcome',
            textBody: 'Thanks for joining.',
        );
    }
};

$dispatcher = new NotificationDispatcher(
    new SyncQueue(),
    ['mail' => new MailChannel($mailer)],
);

$dispatcher->send($user, $notification);   // $user implements NotifiableInterface
```

Resolve `NotificationDispatcher` from the container instead of constructing it by hand once the service provider is booted; call `sendAsync()` to queue delivery for the worker.
