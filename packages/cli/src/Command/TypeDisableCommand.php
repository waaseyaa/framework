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
    name: 'type:disable',
    description: 'Disable a registered content type (does not delete it)',
)]
final class TypeDisableCommand extends Command
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
            ->addArgument('type', InputArgument::REQUIRED, 'The entity type ID to disable (e.g. note)')
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

        if ($this->lifecycleManager->isDisabled($typeId)) {
            $output->writeln(sprintf('<comment>Entity type "%s" is already disabled.</comment>', $typeId));

            return self::SUCCESS;
        }

        // Guard: refuse to disable the last enabled type.
        $definitions = $this->entityTypeManager->getDefinitions();
        $disabledIds = $this->lifecycleManager->getDisabledTypeIds();
        $enabledCount = count(array_filter(
            array_keys($definitions),
            static fn(string $id): bool => $id !== $typeId && !in_array($id, $disabledIds, true),
        ));

        if ($enabledCount === 0) {
            $output->writeln(
                '<error>[DEFAULT_TYPE_DISABLED] Cannot disable the last enabled content type. '
                . 'Register or enable another type first.</error>',
            );

            return self::FAILURE;
        }

        $this->lifecycleManager->disable($typeId, $actor);

        $output->writeln(sprintf('<info>Disabled entity type "%s". Audit entry recorded.</info>', $typeId));

        return self::SUCCESS;
    }
}
