<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\Access\PermissionHandlerInterface;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class PermissionListHandler
{
    public function __construct(
        private readonly PermissionHandlerInterface $permissionHandler,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $permissions = $this->permissionHandler->getPermissions();

        if ($permissions === []) {
            $io->writeln('No permissions registered.');

            return 0;
        }

        $io->writeln(sprintf('%-40s %-30s %s', 'Permission', 'Title', 'Description'));
        $io->writeln(str_repeat('-', 100));

        foreach ($permissions as $id => $info) {
            $io->writeln(sprintf(
                '%-40s %-30s %s',
                $id,
                $info['title'],
                $info['description'],
            ));
        }

        return 0;
    }
}
