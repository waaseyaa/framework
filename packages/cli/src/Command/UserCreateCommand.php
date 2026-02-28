<?php

declare(strict_types=1);

namespace Aurora\CLI\Command;

use Aurora\Entity\EntityTypeManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'user:create',
    description: 'Create a new user account',
)]
class UserCreateCommand extends Command
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'The username for the new account')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address for the user')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Role to assign to the user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getArgument('username');

        $values = ['name' => $username];

        $email = $input->getOption('email');
        if ($email !== null) {
            $values['email'] = $email;
        }

        $role = $input->getOption('role');
        if ($role !== null) {
            $values['roles'] = [$role];
        }

        $storage = $this->entityTypeManager->getStorage('user');
        $user = $storage->create($values);
        $storage->save($user);

        $output->writeln(sprintf('Created user "%s" with ID: %s', $username, (string) $user->id()));

        return Command::SUCCESS;
    }
}
