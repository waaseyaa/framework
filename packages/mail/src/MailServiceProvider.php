<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mail\Driver\NullMailDriver;
use Waaseyaa\Mail\Driver\SendGridDriver;

final class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $mailConfig = $this->config['mail'] ?? [];
        $fromAddress = $mailConfig['from_address'] ?? '';
        $sendgridKey = $mailConfig['sendgrid_api_key'] ?? '';
        $fromName = $mailConfig['from_name'] ?? '';

        $this->singleton(MailDriverInterface::class, fn(): MailDriverInterface => match (true) {
            $sendgridKey !== '' => new SendGridDriver($sendgridKey, $fromAddress, $fromName),
            default => new NullMailDriver(),
        });
    }
}
