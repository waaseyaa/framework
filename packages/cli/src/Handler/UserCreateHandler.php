<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * @api
 */
final class UserCreateHandler
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        /** @var string $username */
        $username = $io->argument('username');

        $values = ['name' => $username];

        $email = $io->option('email');
        if ($email !== null) {
            $values['mail'] = $email;
        }

        $password = $io->option('password');
        if ($password !== null) {
            $values['pass'] = password_hash((string) $password, PASSWORD_DEFAULT);
        }

        $role = $io->option('role');
        if ($role !== null) {
            $values['roles'] = [$role];
        }

        try {
            // C-22 WP3: create/save now go through the canonical repository.
            $repository = $this->entityTypeManager->getRepository('user');
            $user = $repository->create($values);
            $repository->save($user);
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to create user "%s": %s', $username, $e->getMessage()));

            return 1;
        }

        $io->writeln(sprintf('Created user "%s" with ID: %s', $username, (string) $user->id()));

        return 0;
    }
}
