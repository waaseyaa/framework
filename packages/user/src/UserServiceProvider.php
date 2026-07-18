<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Twig\Environment;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mail\MailerInterface;

final class UserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(
            User::class,
            group: 'people',
        ));

        $this->entityType(EntityType::fromClass(
            UserBlock::class,
            group: 'user',
        ));

        $this->singleton(UserBlockService::class, fn() => new UserBlockService(
            $this->resolve(EntityTypeManager::class),
        ));

        $config = $this->config ?? [];
        $mailConfig = $config['mail'] ?? [];
        $authEmailConfigured = trim((string) ($mailConfig['sendgrid_api_key'] ?? '')) !== ''
            && trim((string) ($mailConfig['from_address'] ?? '')) !== '';

        $this->singleton(AuthMailer::class, function () use ($authEmailConfigured, $config): AuthMailer {
            // Resolve the Twig renderer from the container rather than reaching
            // up to the Layer-6 SSR provider's static accessor (a layer
            // violation): the Layer-1 user package must not depend on SSR. SSR
            // registers Twig\Environment when present; without it the mailer is
            // simply unconfigured. Resolved lazily here, so SSR's boot()-time
            // registration is in place by the time AuthMailer is first built.
            $twig = $this->resolveOptional(Environment::class);

            return new AuthMailer(
                mailer: $this->resolve(MailerInterface::class),
                authEmailConfigured: $authEmailConfigured,
                twig: $twig instanceof Environment ? $twig : null,
                baseUrl: $config['app']['url']
                    ?? (getenv('APP_URL') !== false && getenv('APP_URL') !== '' ? (string) getenv('APP_URL') : 'http://localhost:8000'),
                appName: $config['app']['name']
                    ?? (getenv('APP_NAME') !== false && getenv('APP_NAME') !== '' ? (string) getenv('APP_NAME') : 'Waaseyaa'),
                internalFields: $this->resolve(UserInternalFieldReaderInterface::class),
            );
        });
    }

}
