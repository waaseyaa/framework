<?php

declare(strict_types=1);

namespace Aurora\CLI\Command;

use Aurora\Config\ConfigManagerInterface;
use Aurora\Entity\EntityTypeManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'install',
    description: 'Install Aurora CMS with initial configuration',
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
        $this->addOption(
            'site-name',
            null,
            InputOption::VALUE_REQUIRED,
            'The name of the site',
            'Aurora',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $siteName = $input->getOption('site-name');

        // Step 1: Write initial site configuration.
        $output->writeln('Writing initial site configuration...');
        $this->configManager->getActiveStorage()->write('system.site', [
            'name' => $siteName,
            'slogan' => '',
            'mail' => 'admin@example.com',
        ]);

        // Step 2: Create admin user.
        $output->writeln('Creating admin user...');
        $storage = $this->entityTypeManager->getStorage('user');
        $admin = $storage->create([
            'name' => 'admin',
            'email' => 'admin@example.com',
            'roles' => ['administrator'],
        ]);
        $storage->save($admin);

        // Step 3: Output success.
        $output->writeln(sprintf('Aurora CMS "%s" installed successfully.', $siteName));

        return Command::SUCCESS;
    }
}
