<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class RelationshipTypeScaffoldHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $id = trim((string) ($io->option('id') ?? ''));
        $label = trim((string) ($io->option('label') ?? ''));
        $directionality = strtolower(trim((string) ($io->option('directionality') ?? 'directed')));
        $inverse = trim((string) ($io->option('inverse') ?? ''));
        $defaultStatus = (int) ($io->option('default-status') ?? '1') === 1 ? 1 : 0;

        if ($id === '' || $label === '') {
            $io->error('--id and --label are required.');
            return 2;
        }
        if (!in_array($directionality, ['directed', 'bidirectional'], true)) {
            $io->error('--directionality must be "directed" or "bidirectional".');
            return 2;
        }

        $payload = [
            'relationship_type' => [
                'id' => $id,
                'label' => $label,
                'directionality' => $directionality,
                'inverse' => $inverse !== '' ? $inverse : null,
                'default_status' => $defaultStatus,
            ],
        ];

        $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return 0;
    }
}
