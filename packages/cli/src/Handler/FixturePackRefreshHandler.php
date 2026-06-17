<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class FixturePackRefreshHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $inputDir = trim((string) ($io->option('input-dir') ?? ''));
        if ($inputDir === '' || !is_dir($inputDir)) {
            $io->error(sprintf('Input directory does not exist: %s', $inputDir));
            return 1;
        }

        $globResult = glob(rtrim($inputDir, '/') . '/*.json');
        /** @var list<string> $files */
        $files = $globResult !== false ? $globResult : [];
        sort($files);

        if ($files === [] && (bool) $io->option('fail-on-empty')) {
            $io->error('No fixture scenario files found.');
            return 1;
        }

        $scenarios = [];
        $allNodes = [];
        $allRelationships = [];

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if (!is_string($raw)) {
                $io->error(sprintf('Unable to read fixture file: %s', $file));
                return 1;
            }

            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $io->error(sprintf('Invalid JSON in %s: %s', $file, $e->getMessage()));
                return 1;
            }

            if (!is_array($decoded)) {
                $io->error(sprintf('Invalid scenario shape in %s: expected object', $file));
                return 1;
            }

            $scenarioName = pathinfo($file, PATHINFO_FILENAME);
            $nodes = is_array($decoded['nodes'] ?? null) ? $decoded['nodes'] : [];
            $relationships = is_array($decoded['relationships'] ?? null) ? $decoded['relationships'] : [];

            ksort($nodes);
            usort($relationships, static fn(array $a, array $b): int => strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? '')));

            $scenarios[$scenarioName] = [
                'nodes' => $nodes,
                'relationships' => $relationships,
            ];

            foreach ($nodes as $key => $node) {
                $allNodes[(string) $key] = $node;
            }
            foreach ($relationships as $relationship) {
                if (is_array($relationship)) {
                    $allRelationships[] = $relationship;
                }
            }
        }

        ksort($scenarios);
        ksort($allNodes);
        usort($allRelationships, static fn(array $a, array $b): int => strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? '')));

        $aggregate = [
            'contract_version' => 'v1.0',
            'surface' => 'fixture_pack_refresh',
            'scenario_count' => count($scenarios),
            'node_count' => count($allNodes),
            'relationship_count' => count($allRelationships),
            'scenarios' => $scenarios,
            'nodes' => $allNodes,
            'relationships' => $allRelationships,
        ];

        $aggregate['hash'] = sha1(json_encode($aggregate, JSON_THROW_ON_ERROR));
        $json = json_encode($aggregate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $outputPath = trim((string) ($io->option('output') ?? ''));
        if ($outputPath !== '') {
            $dir = dirname($outputPath);
            if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                $io->error(sprintf('Unable to create output directory: %s', $dir));
                return 1;
            }

            file_put_contents($outputPath, $json . PHP_EOL);
            $io->writeln(sprintf('Fixture pack written: %s', $outputPath));
        }

        if ((bool) $io->option('json') || $outputPath === '') {
            $io->writeln($json);
        } else {
            $io->writeln(sprintf(
                'Fixture pack refreshed (%d scenarios, hash %s).',
                $aggregate['scenario_count'],
                $aggregate['hash'],
            ));
        }

        return 0;
    }
}
