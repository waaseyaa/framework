<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mail\MailDriverInterface;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class UserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'user',
            label: 'User',
            description: 'Manage user accounts, roles, and authentication',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
            group: 'people',
            fieldDefinitions: [
                'mail' => [
                    'type' => 'email',
                    'label' => 'Email address',
                    'description' => 'The email address of the user.',
                    'weight' => 5,
                ],
                'email_verified' => [
                    'type' => 'boolean',
                    'label' => 'Email verified',
                    'description' => 'Whether the user has verified their email address.',
                    'weight' => 6,
                ],
                'status' => [
                    'type' => 'boolean',
                    'label' => 'Active',
                    'description' => 'Whether the user account is active.',
                    'weight' => 10,
                ],
                'created' => [
                    'type' => 'timestamp',
                    'label' => 'Member since',
                    'description' => 'The date the user account was created.',
                    'weight' => 20,
                ],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'user_block',
            label: 'User Block',
            description: 'Block rules for restricting user access',
            class: UserBlock::class,
            keys: ['id' => 'ubid', 'uuid' => 'uuid', 'label' => 'blocker_id'],
            group: 'user',
            fieldDefinitions: [
                'blocker_id' => ['type' => 'integer', 'label' => 'Blocker ID', 'weight' => 0],
                'blocked_id' => ['type' => 'integer', 'label' => 'Blocked ID', 'weight' => 1],
                'created_at' => ['type' => 'timestamp', 'label' => 'Created', 'weight' => 10],
            ],
        ));

        $this->singleton(UserBlockService::class, fn() => new UserBlockService(
            $this->resolve(EntityTypeManager::class),
        ));

        $config = $this->config ?? [];
        $this->singleton(AuthMailer::class, fn() => new AuthMailer(
            driver: fn() => $this->resolve(MailDriverInterface::class),
            twig: \Waaseyaa\SSR\SsrServiceProvider::getTwigEnvironment(),
            baseUrl: $config['app']['url'] ?? throw new \RuntimeException(
                "app.url is not configured. Add 'app' => ['url' => 'https://yourapp.com'] to your config/waaseyaa.php.",
            ),
            appName: $config['app']['name'] ?? 'Waaseyaa',
        ));
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $router->addRoute(
            'api.user.me',
            RouteBuilder::create('/api/user/me')
                ->controller('user.me')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.auth.login',
            RouteBuilder::create('/api/auth/login')
                ->controller('auth.login')
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.logout',
            RouteBuilder::create('/api/auth/logout')
                ->controller('auth.logout')
                ->allowAll()
                ->methods('POST')
                ->build(),
        );
    }
}
