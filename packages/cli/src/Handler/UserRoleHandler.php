<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * @api
 */
final class UserRoleHandler
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        /** @var string $userId */
        $userId = $io->argument('user_id');
        /** @var string $role */
        $role = $io->argument('role');
        $remove = (bool) $io->option('remove');

        $storage = $this->entityTypeManager->getStorage('user');
        $user = $storage->load($userId);

        if ($user === null) {
            $io->error(sprintf('User with ID "%s" not found.', $userId));

            return 1;
        }

        /** @var array<string> $roles */
        $roles = $user->get('roles') ?? [];

        if ($remove) {
            $roles = array_values(array_filter($roles, fn(string $r): bool => $r !== $role));
        } else {
            if (in_array($role, $roles, true)) {
                $io->writeln(sprintf('User %s already has role "%s". No change.', $userId, $role));

                return 0;
            }
            $roles[] = $role;
        }

        $user->set('roles', $roles);
        $storage->save($user);

        if ($remove) {
            $io->writeln(sprintf('Removed role "%s" from user %s.', $role, $userId));
        } else {
            $io->writeln(sprintf('Added role "%s" to user %s.', $role, $userId));
        }

        return 0;
    }
}
