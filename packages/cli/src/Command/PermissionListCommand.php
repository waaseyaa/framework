<?php

declare(strict_types=1);

namespace Aurora\CLI\Command;

use Aurora\Access\PermissionHandlerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists all registered permissions.
 */
#[AsCommand(
    name: 'permission:list',
    description: 'List all registered permissions',
)]
final class PermissionListCommand extends Command
{
    public function __construct(
        private readonly PermissionHandlerInterface $permissionHandler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $permissions = $this->permissionHandler->getPermissions();

        if ($permissions === []) {
            $output->writeln('No permissions registered.');

            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Permission', 'Title', 'Description']);

        foreach ($permissions as $id => $info) {
            $table->addRow([
                $id,
                $info['title'] ?? '',
                $info['description'] ?? '',
            ]);
        }

        $table->render();

        return self::SUCCESS;
    }
}
