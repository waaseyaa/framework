<?php

declare(strict_types=1);

namespace Waaseyaa\User\Http;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\User\User;

/**
 * Handles authentication API endpoints: me, login, logout.
 */
final class AuthController
{
    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Returns the current user's data or a 401 payload for anonymous users.
     *
     * @return array{statusCode: int, data?: array<string, mixed>, errors?: list<array<string, string>>}
     */
    public function me(AccountInterface $account): array
    {
        if (!$account->isAuthenticated()) {
            if (\PHP_SAPI === 'cli-server') {
                $this->logger->info('Admin endpoint returned 401. For local development, set APP_ENV=local and WAASEYAA_DEV_FALLBACK_ACCOUNT=true (or use `composer dev` which sets both automatically).');
            }

            return [
                'statusCode' => 401,
                'errors' => [['status' => '401', 'title' => 'Unauthorized', 'detail' => 'Not authenticated.']],
            ];
        }

        $data = [
            'id' => $account->id(),
            'name' => $account instanceof User ? $account->getName() : '',
            'email' => $account instanceof User ? $account->getEmail() : '',
            'roles' => $account->getRoles(),
        ];

        return ['statusCode' => 200, 'data' => $data];
    }

    /**
     * Looks up a user by name in storage. Returns null if not found.
     */
    public function findUserByName(EntityStorageInterface $storage, string $name): ?User
    {
        $ids = $storage->getQuery()
            ->condition('name', $name)
            ->condition('status', 1)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $user = $storage->load(reset($ids));

        return $user instanceof User ? $user : null;
    }
}
