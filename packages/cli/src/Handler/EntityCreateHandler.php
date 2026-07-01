<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Create an entity, taking field values WITHOUT inline JSON so authoring works
 * cleanly in PowerShell, cmd, and POSIX shells (author-path FR-001/002):
 *
 *   --field name=value         repeatable; a scalar field value
 *   --field-file name=@path     repeatable; load a field's value from a file
 *   --values-file path.json     the whole value set as a JSON file
 *   --values-file -             read the whole value set as JSON from stdin
 *   --values '{...}'            inline JSON (still supported)
 *
 * Merge precedence (later wins): --values, then --values-file/stdin, then
 * --field, then --field-file. So a JSON base can be overridden per-field, and a
 * large field (e.g. a Markdown body) loaded from a file overrides everything.
 *
 * @api
 */
final class EntityCreateHandler
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        /** Stdin source for `--values-file -`; overridable in tests. */
        private readonly string $stdinPath = 'php://stdin',
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        /** @var string $entityType */
        $entityType = $io->argument('entity_type');

        try {
            $values = $this->collectValues($io);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        try {
            // C-22 WP3: create/save now go through the canonical repository.
            $repository = $this->entityTypeManager->getRepository($entityType);
            $entity = $repository->create($values);
            $repository->save($entity);
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to create %s entity: %s', $entityType, $e->getMessage()));

            return 1;
        }

        $io->writeln(sprintf('Created %s entity with ID: %s', $entityType, (string) $entity->id()));

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectValues(SymfonyCommandIO $io): array
    {
        $values = [];

        // 1. Inline --values JSON (base).
        $valuesJson = $io->option('values');
        if (is_string($valuesJson) && trim($valuesJson) !== '' && trim($valuesJson) !== '{}') {
            $values = $this->decodeJson($valuesJson, '--values');
        }

        // 2. --values-file path.json (or `-` for stdin).
        $valuesFile = $io->option('values-file');
        if (is_string($valuesFile) && $valuesFile !== '') {
            $raw = $valuesFile === '-'
                ? $this->readStream($this->stdinPath, 'stdin')
                : $this->readStream($valuesFile, sprintf('--values-file "%s"', $valuesFile));
            $values = array_merge($values, $this->decodeJson($raw, $valuesFile === '-' ? 'stdin' : $valuesFile));
        }

        // 3. --field name=value (repeatable scalar overrides).
        foreach ($this->arrayOption($io, 'field') as $entry) {
            [$name, $val] = $this->splitPair($entry, '--field');
            $values[$name] = $val;
        }

        // 4. --field-file name=@path (repeatable; load a field from a file).
        foreach ($this->arrayOption($io, 'field-file') as $entry) {
            [$name, $spec] = $this->splitPair($entry, '--field-file');
            $path = ltrim($spec, '@');
            if ($path === '') {
                throw new \RuntimeException(sprintf('--field-file "%s" is missing a file path (expected name=@path).', $entry));
            }
            $values[$name] = $this->readStream($path, sprintf('--field-file "%s"', $name));
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function arrayOption(SymfonyCommandIO $io, string $name): array
    {
        $value = $io->option($name);
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $value),
            static fn(string $v): bool => $v !== '',
        ));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPair(string $entry, string $option): array
    {
        $pos = strpos($entry, '=');
        if ($pos === false || $pos === 0) {
            throw new \RuntimeException(sprintf('%s "%s" must be in name=value form.', $option, $entry));
        }

        return [substr($entry, 0, $pos), substr($entry, $pos + 1)];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json, string $source): array
    {
        try {
            $decoded = json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Invalid JSON from %s: %s', $source, $e->getMessage()));
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Expected a JSON object from %s, got %s.', $source, get_debug_type($decoded)));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function readStream(string $path, string $label): string
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Could not read %s.', $label));
        }

        return $raw;
    }
}
