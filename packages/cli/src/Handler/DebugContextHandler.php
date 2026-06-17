<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class DebugContextHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $entityType = strtolower(trim((string) $io->option('entity-type')));
        $entityId = trim((string) $io->option('entity-id'));
        $workflowState = strtolower(trim((string) $io->option('workflow-state')));
        $status = (int) $io->option('status') === 1 ? 1 : 0;
        $viewMode = trim((string) $io->option('view-mode'));
        $preview = (int) $io->option('preview') === 1;

        if ($entityType === '' || $entityId === '' || $workflowState === '' || $viewMode === '') {
            $io->error('Required options are empty.');

            return 2;
        }

        $counts = $this->parseRelationshipCounts((string) $io->option('relationship-counts'));
        if ($counts === null) {
            $io->error('Invalid --relationship-counts. Expected outbound:inbound (e.g. 4:2).');

            return 2;
        }

        $normalizedState = $workflowState;
        $isPublic = ($normalizedState === 'published') && $status === 1;

        $payload = [
            'debug_panel' => [
                'workflow' => [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'workflow_state' => $normalizedState,
                    'status' => $status,
                    'is_public' => $isPublic,
                ],
                'traversal' => [
                    'source' => ['type' => $entityType, 'id' => $entityId],
                    'counts' => [
                        'outbound' => $counts['outbound'],
                        'inbound' => $counts['inbound'],
                        'total' => $counts['outbound'] + $counts['inbound'],
                    ],
                ],
                'ssr' => [
                    'view_mode' => $viewMode,
                    'preview' => $preview,
                    'cache_scope' => $preview ? 'preview' : 'public',
                    'variant_seed' => [
                        'workflow_state' => $normalizedState,
                        'traversal_total' => $counts['outbound'] + $counts['inbound'],
                    ],
                ],
            ],
        ];

        $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return 0;
    }

    /**
     * @return array{outbound: int, inbound: int}|null
     */
    private function parseRelationshipCounts(string $raw): ?array
    {
        $parts = explode(':', trim($raw));
        if (count($parts) !== 2) {
            return null;
        }

        $outbound = trim($parts[0]);
        $inbound = trim($parts[1]);
        if (!ctype_digit($outbound) || !ctype_digit($inbound)) {
            return null;
        }

        return [
            'outbound' => (int) $outbound,
            'inbound' => (int) $inbound,
        ];
    }
}
