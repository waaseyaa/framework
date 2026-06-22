<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class FixtureScaffoldHandler
{
    /** @var list<string> */
    private const array VALID_STATES = ['draft', 'review', 'published', 'archived'];

    public function execute(SymfonyCommandIO $io): int
    {
        $key = trim((string) ($io->option('key') ?? ''));
        $title = trim((string) ($io->option('title') ?? ''));
        $bundle = trim((string) ($io->option('bundle') ?? ''));
        $workflowState = strtolower(trim((string) ($io->option('workflow-state') ?? '')));
        $relationshipType = trim((string) ($io->option('relationship-type') ?? ''));
        $toKey = trim((string) ($io->option('to-key') ?? ''));

        if ($key === '' || $title === '' || $bundle === '') {
            $io->error('--key, --title, and --bundle are required.');
            return 2;
        }
        if (!in_array($workflowState, self::VALID_STATES, true)) {
            $io->error(sprintf(
                'Invalid --workflow-state "%s". Allowed: %s',
                $workflowState,
                implode(', ', self::VALID_STATES),
            ));
            return 2;
        }

        if (($relationshipType === '') !== ($toKey === '')) {
            $io->error('--relationship-type and --to-key must be provided together.');
            return 2;
        }

        $timestamp = max(0, (int) ($io->option('timestamp') ?? 1735689600));
        $uid = max(0, (int) ($io->option('uid') ?? 1));
        $statusOption = $io->option('status');
        $itemStatus = $statusOption === null
            ? ($workflowState === 'published' ? 1 : 0)
            : ((int) $statusOption === 1 ? 1 : 0);

        $node = [
            'title' => $title,
            'type' => $bundle,
            'uid' => $uid,
            'created' => $timestamp,
            'changed' => $timestamp,
            'status' => $itemStatus,
            'workflow_state' => $workflowState,
        ];

        $scenario = [
            'nodes' => [
                $key => $node,
            ],
            'relationships' => [],
        ];

        if ($relationshipType !== '') {
            $relationshipStatus = (int) ($io->option('relationship-status') ?? 1) === 1 ? 1 : 0;
            $scenario['relationships'][] = [
                'key' => sprintf('%s_to_%s_%s', $key, $toKey, $relationshipType),
                'relationship_type' => $relationshipType,
                'from' => $key,
                'to' => $toKey,
                'status' => $relationshipStatus,
                'start_date' => $timestamp,
                'end_date' => null,
            ];
        }

        ksort($scenario['nodes']);
        usort($scenario['relationships'], static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));

        $json = json_encode($scenario, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $outputPath = trim((string) ($io->option('output') ?? ''));
        if ($outputPath !== '') {
            $dir = dirname($outputPath);
            if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                $io->error(sprintf('Unable to create output directory: %s', $dir));
                return 1;
            }

            file_put_contents($outputPath, $json . PHP_EOL);
            $io->writeln(sprintf('Fixture scenario written: %s', $outputPath));
            return 0;
        }

        $io->writeln($json);

        return 0;
    }
}
