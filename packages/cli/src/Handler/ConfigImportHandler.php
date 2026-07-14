<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\Exception\ConfigImportFailedException;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Workflows\Validation\WorkflowAssignmentsValidator;

/**
 * @api
 */
final class ConfigImportHandler
{
    public function __construct(
        private readonly ConfigManagerInterface $configManager,
        private readonly ?WorkflowAssignmentsValidator $workflowAssignmentsValidator = null,
        private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        if ($this->workflowAssignmentsValidator !== null && $this->entityTypeManager !== null) {
            $assignments = $this->configManager->getSyncStorage()->read('workflows.assignments');
            if (is_array($assignments)) {
                $violations = $this->workflowAssignmentsValidator->validate($assignments, $this->entityTypeManager);
                if ($violations !== []) {
                    foreach ($violations as $violation) {
                        $io->error($violation);
                    }

                    return 1;
                }
            }
        }

        try {
            $result = $this->configManager->import();
        } catch (ConfigImportFailedException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $io->writeln(sprintf('Created: %d', count($result->created)));
        $io->writeln(sprintf('Updated: %d', count($result->updated)));
        $io->writeln(sprintf('Deleted: %d', count($result->deleted)));

        if ($result->hasErrors()) {
            foreach ($result->errors as $error) {
                $io->error(sprintf('Error: %s', $error));
            }

            return 1;
        }

        $io->writeln('Configuration imported successfully.');

        return 0;
    }
}
