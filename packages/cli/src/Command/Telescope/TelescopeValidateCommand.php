<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Telescope;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Telescope\CodifiedContext\Validator\EmbeddingProviderInterface;

/**
 * Validates codified context for a session and computes drift score.
 */
#[AsCommand(
    name: 'telescope:validate',
    description: 'Validate codified context for a session and compute drift score',
)]
final class TelescopeValidateCommand extends Command
{
    public function __construct(private readonly EmbeddingProviderInterface $embeddingProvider)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('session-id', InputArgument::REQUIRED, 'Session ID to validate')
            ->addArgument('output-file', InputArgument::OPTIONAL, 'Path to write validation report JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessionId = $input->getArgument('session-id');
        $output->writeln("Validating session: {$sessionId}");
        // Skeleton — real integration loads session data from store
        $output->writeln('Validator Agent CLI ready. Provide session data via store integration.');

        return Command::SUCCESS;
    }
}
