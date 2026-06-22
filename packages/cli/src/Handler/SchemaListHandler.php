<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Schema\SchemaRegistryInterface;

/**
 * @api
 */
final class SchemaListHandler
{
    public function __construct(
        private readonly SchemaRegistryInterface $registry,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $entries = $this->registry->list();

        if ($entries === []) {
            $io->writeln('No schemas found.');
            return 0;
        }

        $io->writeln(sprintf('%-30s %-10s %-15s %-12s %-12s %s', 'ID', 'Kind', 'Version', 'Compat', 'Stability', 'Schema Path'));
        $io->writeln(str_repeat('-', 100));

        foreach ($entries as $entry) {
            $io->writeln(sprintf(
                '%-30s %-10s %-15s %-12s %-12s %s',
                $entry->id,
                $entry->schemaKind,
                $entry->version,
                $entry->compatibility,
                $entry->stability,
                $entry->schemaPath,
            ));
        }

        return 0;
    }
}
