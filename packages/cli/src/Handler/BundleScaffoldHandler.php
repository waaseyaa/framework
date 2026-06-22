<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class BundleScaffoldHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $id = trim((string) ($io->option('id') ?? ''));
        $label = trim((string) ($io->option('label') ?? ''));
        $entityType = trim((string) ($io->option('entity-type') ?? ''));
        $workflow = trim((string) ($io->option('workflow') ?? ''));

        if ($id === '' || $label === '' || $entityType === '' || $workflow === '') {
            $io->error('--id, --label, --entity-type, and --workflow are required.');
            return 2;
        }

        /** @var array<mixed> $rawFields */
        $rawFields = (array) ($io->option('field') ?? []);
        $fields = $this->parseFields($rawFields);
        if ($fields === null) {
            $io->error('Invalid --field format. Use name:type:required (required must be 0 or 1).');
            return 2;
        }

        if ($fields === []) {
            $fields = [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'text', 'required' => false],
            ];
        }

        usort($fields, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        $payload = [
            'bundle' => [
                'id' => $id,
                'label' => $label,
                'entity_type' => $entityType,
                'workflow' => $workflow,
                'fields' => $fields,
            ],
        ];

        $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return 0;
    }

    /**
     * @param array<mixed> $rawFields
     * @return list<array{name: string, type: string, required: bool}>|null
     */
    private function parseFields(array $rawFields): ?array
    {
        $fields = [];
        foreach ($rawFields as $raw) {
            if (!is_string($raw)) {
                return null;
            }

            $parts = explode(':', $raw);
            if (count($parts) !== 3) {
                return null;
            }

            [$name, $type, $required] = array_map(static fn(string $v): string => trim($v), $parts);
            if ($name === '' || $type === '' || !in_array($required, ['0', '1'], true)) {
                return null;
            }

            $fields[] = [
                'name' => $name,
                'type' => $type,
                'required' => $required === '1',
            ];
        }

        return $fields;
    }
}
