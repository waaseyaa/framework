<?php

declare(strict_types=1);

namespace Waaseyaa\Notification;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mail\MailerInterface;
use Waaseyaa\Notification\Channel\DatabaseChannel;
use Waaseyaa\Notification\Channel\MailChannel;
use Waaseyaa\Notification\Job\SendNotificationHandler;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\Worker\Worker;

final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(NotificationDispatcher::class, function (): NotificationDispatcher {
            return new NotificationDispatcher(
                $this->resolve(QueueInterface::class),
                $this->buildChannels(),
            );
        });
    }

    public function boot(): void
    {
        // Register the async notification handler with the queue worker
        try {
            $worker = $this->resolve(Worker::class);
            $worker->addHandler(new SendNotificationHandler($this->buildChannels()));
        } catch (\Throwable) {
            // Worker not available (e.g., HTTP-only context)
        }
    }

    /**
     * @return array<string, ChannelInterface>
     */
    private function buildChannels(): array
    {
        $channels = [];

        try {
            $mailer = $this->resolve(MailerInterface::class);
            $channels['mail'] = new MailChannel($mailer);
        } catch (\Throwable) {
            // Mail not configured
        }

        try {
            $database = $this->resolve(DatabaseInterface::class);
            $channels['database'] = new DatabaseChannel($database);
        } catch (\Throwable) {
            // Database not configured
        }

        return $channels;
    }
}
