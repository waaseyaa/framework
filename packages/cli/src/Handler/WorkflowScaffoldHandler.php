<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class WorkflowScaffoldHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $id = trim((string) ($io->option('id') ?? ''));
        $bundle = trim((string) ($io->option('bundle') ?? ''));

        if ($id === '' || $bundle === '') {
            $io->error('--id and --bundle are required.');
            return 2;
        }

        /** @var array<mixed> $rawStates */
        $rawStates = (array) ($io->option('state') ?? []);
        $states = $this->parseStates($rawStates);

        /** @var array<mixed> $rawTransitions */
        $rawTransitions = (array) ($io->option('transition') ?? []);
        $transitions = $this->parseTransitions($rawTransitions);

        if ($transitions === null) {
            $io->error('Invalid --transition format. Use id:from:to:permission.');
            return 2;
        }

        if ($states === []) {
            $states = ['draft', 'review', 'published', 'archived'];
        }
        if ($transitions === []) {
            $transitions = [
                ['id' => 'submit_review', 'from' => 'draft', 'to' => 'review', 'permission' => 'submit article for review'],
                ['id' => 'publish', 'from' => 'review', 'to' => 'published', 'permission' => 'publish article content'],
                ['id' => 'archive', 'from' => 'published', 'to' => 'archived', 'permission' => 'archive article content'],
            ];
        }

        sort($states);
        usort($transitions, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

        $payload = [
            'workflow' => [
                'id' => $id,
                'bundle' => $bundle,
                'states' => array_values(array_unique($states)),
                'transitions' => $transitions,
            ],
        ];

        $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return 0;
    }

    /**
     * @param array<mixed> $rawStates
     * @return list<string>
     */
    private function parseStates(array $rawStates): array
    {
        $states = [];
        foreach ($rawStates as $state) {
            if (!is_string($state)) {
                continue;
            }
            $normalized = strtolower(trim($state));
            if ($normalized !== '') {
                $states[] = $normalized;
            }
        }

        return $states;
    }

    /**
     * @param array<mixed> $rawTransitions
     * @return list<array{id: string, from: string, to: string, permission: string}>|null
     */
    private function parseTransitions(array $rawTransitions): ?array
    {
        $transitions = [];
        foreach ($rawTransitions as $raw) {
            if (!is_string($raw)) {
                return null;
            }
            $parts = explode(':', $raw);
            if (count($parts) !== 4) {
                return null;
            }
            [$tid, $from, $to, $permission] = array_map(static fn(string $v): string => trim($v), $parts);
            if ($tid === '' || $from === '' || $to === '' || $permission === '') {
                return null;
            }
            $transitions[] = [
                'id' => $tid,
                'from' => $from,
                'to' => $to,
                'permission' => $permission,
            ];
        }

        return $transitions;
    }
}
