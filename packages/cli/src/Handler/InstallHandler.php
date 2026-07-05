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
        // C-22 WP3: create/save now go through the canonical repository.
        $repository = $this->entityTypeManager->getRepository('user');
        $userValues = [
            'name' => 'admin',
            // Waaseyaa\User\User uses the entity keys `mail`/`pass`, not
            // `email`/`password` — the latter are unrecognized by
            // SqlStorageDriver::splitForWrite() and route verbatim into the
            // `_data` JSON blob (audit A7 F1). The password MUST be hashed
            // here (mirroring UserCreateHandler) — never persist plaintext.
            'mail' => $adminEmail,
            'roles' => ['administrator'],
        ];
        if (is_string($adminPassword) && $adminPassword !== '') {
            $userValues['pass'] = password_hash($adminPassword, PASSWORD_DEFAULT);
        }
        $entity = $repository->create($userValues);
        $repository->save($entity);

        $io->writeln(sprintf('Waaseyaa "%s" installed successfully.', $siteName));

        return 0;
    }
}
