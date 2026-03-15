<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[AsCommand(
    name: 'user:role',
    description: 'Add or remove a role from a user',
)]
class UserRoleCommand extends Command
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user_id', InputArgument::REQUIRED, 'The user ID')
            ->addArgument('role', InputArgument::REQUIRED, 'The role to add or remove')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the role instead of adding it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = $input->getArgument('user_id');
        $role = $input->getArgument('role');
        $remove = $input->getOption('remove');

        $storage = $this->entityTypeManager->getStorage('user');
        $user = $storage->load($userId);

        if ($user === null) {
            $output->writeln(sprintf('<error>User with ID "%s" not found.</error>', $userId));

            return Command::FAILURE;
        }

        /** @var array<string> $roles */
        $roles = $user->get('roles') ?? [];

        if ($remove) {
            $roles = array_values(array_filter($roles, fn(string $r): bool => $r !== $role));
        } else {
            if (in_array($role, $roles, true)) {
                $output->writeln(sprintf('User %s already has role "%s". No change.', $userId, $role));
                return Command::SUCCESS;
            }
            $roles[] = $role;
        }

        $user->set('roles', $roles);
        $storage->save($user);

        if ($remove) {
            $output->writeln(sprintf('Removed role "%s" from user %s.', $role, $userId));
        } else {
            $output->writeln(sprintf('Added role "%s" to user %s.', $role, $userId));
        }

        return Command::SUCCESS;
    }
}
