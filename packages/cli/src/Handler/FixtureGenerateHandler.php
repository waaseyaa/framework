<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class FixtureGenerateHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $template = strtolower(trim((string) ($io->option('template') ?? '')));
        $count = max(2, (int) ($io->option('count') ?? 8));
        $prefix = trim((string) ($io->option('prefix') ?? ''));
        $bundle = trim((string) ($io->option('bundle') ?? ''));
        $timestamp = max(0, (int) ($io->option('timestamp') ?? 1735689600));

        if ($template === '' || $prefix === '' || $bundle === '') {
            $io->error('--template, --prefix, and --bundle are required.');
            return 2;
        }
        if (!in_array($template, ['fanout', 'chain', 'mixed-workflow'], true)) {
            $io->error('Unknown --template. Allowed: fanout, chain, mixed-workflow.');
            return 2;
        }

        $scenario = match ($template) {
            'fanout' => $this->fanoutScenario($count, $prefix, $bundle, $timestamp),
            'chain' => $this->chainScenario($count, $prefix, $bundle, $timestamp),
            'mixed-workflow' => $this->mixedWorkflowScenario($count, $prefix, $bundle, $timestamp),
        };

        ksort($scenario['nodes']);
        usort($scenario['relationships'], static fn(array $a, array $b): int => strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? '')));

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

    /**
     * @return array{nodes: array<string, array<string, mixed>>, relationships: list<array<string, mixed>>}
     */
    private function fanoutScenario(int $count, string $prefix, string $bundle, int $timestamp): array
    {
        $nodes = [];
        $relationships = [];
        $anchor = sprintf('%s_%03d', $prefix, 1);

        for ($i = 1; $i <= $count; $i++) {
            $key = sprintf('%s_%03d', $prefix, $i);
            $nodes[$key] = $this->node($i, $bundle, $timestamp, 'published');
            if ($i > 1) {
                $relationships[] = $this->relationship(
                    key: sprintf('%s_to_%s_related', $anchor, $key),
                    from: $anchor,
                    to: $key,
                    relationshipType: 'related',
                    itemStatus: 1,
                    startDate: $timestamp - ($i * 60),
                );
            }
        }

        return ['nodes' => $nodes, 'relationships' => $relationships];
    }

    /**
     * @return array{nodes: array<string, array<string, mixed>>, relationships: list<array<string, mixed>>}
     */
    private function chainScenario(int $count, string $prefix, string $bundle, int $timestamp): array
    {
        $nodes = [];
        $relationships = [];
        for ($i = 1; $i <= $count; $i++) {
            $key = sprintf('%s_%03d', $prefix, $i);
            $nodes[$key] = $this->node($i, $bundle, $timestamp, 'published');
            if ($i < $count) {
                $to = sprintf('%s_%03d', $prefix, $i + 1);
                $relationships[] = $this->relationship(
                    key: sprintf('%s_to_%s_related', $key, $to),
                    from: $key,
                    to: $to,
                    relationshipType: 'related',
                    itemStatus: 1,
                    startDate: $timestamp - ($i * 120),
                );
            }
        }

        return ['nodes' => $nodes, 'relationships' => $relationships];
    }

    /**
     * @return array{nodes: array<string, array<string, mixed>>, relationships: list<array<string, mixed>>}
     */
    private function mixedWorkflowScenario(int $count, string $prefix, string $bundle, int $timestamp): array
    {
        $nodes = [];
        $relationships = [];
        $states = ['published', 'review', 'draft', 'archived'];

        for ($i = 1; $i <= $count; $i++) {
            $key = sprintf('%s_%03d', $prefix, $i);
            $state = $states[($i - 1) % count($states)];
            $nodes[$key] = $this->node($i, $bundle, $timestamp, $state);
            if ($i < $count) {
                $to = sprintf('%s_%03d', $prefix, $i + 1);
                $relationships[] = $this->relationship(
                    key: sprintf('%s_to_%s_supports', $key, $to),
                    from: $key,
                    to: $to,
                    relationshipType: 'supports',
                    itemStatus: $state === 'published' ? 1 : 0,
                    startDate: $timestamp - ($i * 180),
                );
            }
        }

        return ['nodes' => $nodes, 'relationships' => $relationships];
    }

    /**
     * @return array<string, mixed>
     */
    private function node(int $position, string $bundle, int $timestamp, string $workflowState): array
    {
        return [
            'title' => sprintf('Fixture Node %03d', $position),
            'type' => $bundle,
            'uid' => 1,
            'created' => $timestamp + ($position * 60),
            'changed' => $timestamp + ($position * 60),
            'status' => $workflowState === 'published' ? 1 : 0,
            'workflow_state' => $workflowState,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function relationship(
        string $key,
        string $from,
        string $to,
        string $relationshipType,
        int $itemStatus,
        int $startDate,
    ): array {
        return [
            'key' => $key,
            'relationship_type' => $relationshipType,
            'from' => $from,
            'to' => $to,
            'status' => $itemStatus,
            'start_date' => $startDate,
            'end_date' => null,
        ];
    }
}
