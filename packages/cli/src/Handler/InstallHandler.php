<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class InstallHandler
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ConfigManagerInterface $configManager,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $siteName = $io->option('site-name') ?? 'Waaseyaa';
        $siteMail = $io->option('site-mail') ?? 'admin@example.com';
        $adminEmail = $io->option('admin-email') ?? 'admin@example.com';
        $adminPassword = $io->option('admin-password');

        if ($adminPassword === null || $adminPassword === '') {
            $io->writeln('Warning: No --admin-password provided. The admin account will have no password.');
        }

        // Step 1: Write initial site configuration.
        $io->writeln('Writing initial site configuration...');
        $this->configManager->getActiveStorage()->write('system.site', [
            'name' => $siteName,
            'slogan' => '',
            'mail' => $siteMail,
        ]);

        // Step 2: Create admin user.
        $io->writeln('Creating admin user...');
        $storage = $this->entityTypeManager->getStorage('user');
        $userValues = [
            'name' => 'admin',
            'email' => $adminEmail,
            'roles' => ['administrator'],
        ];
        if (is_string($adminPassword) && $adminPassword !== '') {
            $userValues['password'] = $adminPassword;
        }
        $entity = $storage->create($userValues);
        $storage->save($entity);

        $io->writeln(sprintf('Waaseyaa "%s" installed successfully.', $siteName));

        return 0;
    }
}
