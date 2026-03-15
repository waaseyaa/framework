<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[AsCommand(
    name: 'install',
    description: 'Install Waaseyaa with initial configuration',
)]
class InstallCommand extends Command
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ConfigManagerInterface $configManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('site-name', null, InputOption::VALUE_REQUIRED, 'The name of the site', 'Waaseyaa')
            ->addOption('site-mail', null, InputOption::VALUE_REQUIRED, 'Site email address', 'admin@example.com')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Admin user email', 'admin@example.com')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Admin user password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $siteName = $input->getOption('site-name');
        $siteMail = $input->getOption('site-mail');
        $adminEmail = $input->getOption('admin-email');
        $adminPassword = $input->getOption('admin-password');

        if ($adminPassword === null || $adminPassword === '') {
            $output->writeln('<comment>Warning: No --admin-password provided. The admin account will have no password.</comment>');
        }

        // Step 1: Write initial site configuration.
        $output->writeln('Writing initial site configuration...');
        $this->configManager->getActiveStorage()->write('system.site', [
            'name' => $siteName,
            'slogan' => '',
            'mail' => $siteMail,
        ]);

        // Step 2: Create admin user.
        $output->writeln('Creating admin user...');
        $storage = $this->entityTypeManager->getStorage('user');
        $userValues = [
            'name' => 'admin',
            'email' => $adminEmail,
            'roles' => ['administrator'],
        ];
        if ($adminPassword !== null && $adminPassword !== '') {
            $userValues['password'] = $adminPassword;
        }
        $admin = $storage->create($userValues);
        $storage->save($admin);

        // Step 3: Output success.
        $output->writeln(sprintf('Waaseyaa "%s" installed successfully.', $siteName));

        return Command::SUCCESS;
    }
}
