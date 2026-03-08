<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[AsCommand(
    name: 'type:enable',
    description: 'Re-enable a previously disabled content type',
)]
final class TypeEnableCommand extends Command
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityTypeLifecycleManager $lifecycleManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'The entity type ID to enable (e.g. note)')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor ID for the audit log', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $typeId */
        $typeId = $input->getArgument('type');
        /** @var string $actor */
        $actor = $input->getOption('actor') ?? 'cli';

        if (!$this->entityTypeManager->hasDefinition($typeId)) {
            $output->writeln(sprintf('<error>Unknown entity type: "%s"</error>', $typeId));

            return self::FAILURE;
        }

        if (!$this->lifecycleManager->isDisabled($typeId)) {
            $output->writeln(sprintf('<comment>Entity type "%s" is already enabled.</comment>', $typeId));

            return self::SUCCESS;
        }

        $this->lifecycleManager->enable($typeId, $actor);

        $output->writeln(sprintf('<info>Enabled entity type "%s". Audit entry recorded.</info>', $typeId));

        return self::SUCCESS;
    }
}
