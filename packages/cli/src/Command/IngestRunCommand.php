<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\CLI\Ingestion\IngestionEnvelopeNormalizer;
use Waaseyaa\CLI\Ingestion\SchemaDiagnosticEmitter;
use Waaseyaa\CLI\Ingestion\SchemaValidator;

#[AsCommand(
    name: 'ingest:run',
    description: 'Run deterministic structured/unstructured ingestion and emit mapped content payloads',
)]
final class IngestRunCommand extends Command
{
    private const array VALID_STATES = ['draft', 'review', 'published', 'archived'];

    protected function configure(): void
    {
        $this
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Input file path (.json, .txt, .md)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Input format: auto|structured|unstructured', 'auto')
            ->addOption('default-bundle', null, InputOption::VALUE_REQUIRED, 'Default bundle for mapped nodes', 'teaching')
            ->addOption('default-workflow-state', null, InputOption::VALUE_REQUIRED, 'Default workflow state', 'draft')
            ->addOption('author-id', null, InputOption::VALUE_REQUIRED, 'Mapped author UID', '1')
            ->addOption('timestamp', null, InputOption::VALUE_REQUIRED, 'Deterministic ingest timestamp', '1735689600')
            ->addOption('batch-id', null, InputOption::VALUE_REQUIRED, 'Batch idempotency key (defaults to deterministic hash)')
            ->addOption('policy', null, InputOption::VALUE_REQUIRED, 'Ingestion policy: atomic_fail_fast|validate_only', 'atomic_fail_fast')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source identifier for audit metadata', 'manual://default')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Optional mapped output file (.json)')
            ->addOption('diagnostics-output', null, InputOption::VALUE_REQUIRED, 'Optional diagnostics output file (.json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputPath = trim((string) $input->getOption('input'));
        if ($inputPath === '') {
            $output->writeln('<error>--input is required.</error>');
            return Command::INVALID;
        }
        if (!is_file($inputPath) || !is_readable($inputPath)) {
            $output->writeln(sprintf('<error>Input file is not readable: %s</error>', $inputPath));
            return Command::FAILURE;
        }

        $format = strtolower(trim((string) $input->getOption('format')));
        if (!in_array($format, ['auto', 'structured', 'unstructured'], true)) {
            $output->writeln('<error>Invalid --format. Allowed: auto, structured, unstructured.</error>');
            return Command::INVALID;
        }

        $defaultBundle = trim((string) $input->getOption('default-bundle'));
        $defaultState = strtolower(trim((string) $input->getOption('default-workflow-state')));
        if (!in_array($defaultState, self::VALID_STATES, true)) {
            $output->writeln(sprintf('<error>Invalid --default-workflow-state "%s".</error>', $defaultState));
            return Command::INVALID;
        }

        $timestamp = max(0, (int) $input->getOption('timestamp'));
        $authorId = max(0, (int) $input->getOption('author-id'));
        $policy = strtolower(trim((string) $input->getOption('policy')));
        $source = trim((string) $input->getOption('source'));
        if ($source === '') {
            $output->writeln('<error>--source must be non-empty.</error>');
            return Command::INVALID;
        }

        $raw = file_get_contents($inputPath);
        if ($raw === false) {
            $output->writeln(sprintf('<error>Unable to read input file: %s</error>', $inputPath));
            return Command::FAILURE;
        }

        $resolvedFormat = $format;
        if ($resolvedFormat === 'auto') {
            $resolvedFormat = str_ends_with(strtolower($inputPath), '.json') ? 'structured' : 'unstructured';
        }
        $batchId = trim((string) $input->getOption('batch-id'));
        if ($batchId === '') {
            $batchId = 'batch_' . substr(sha1($inputPath . '|' . $source . '|' . $raw), 0, 16);
        }

        $diagnostics = [
            'schema' => [],
            'errors' => [],
            'warnings' => [],
        ];

        $records = $resolvedFormat === 'structured'
            ? $this->parseStructured($raw, $diagnostics)
            : $this->parseUnstructured($raw, $diagnostics);

        $schemaEnvelope = $this->buildSchemaEnvelope(
            records: $records,
            batchId: $batchId,
            sourceSetUri: $source,
            policy: $policy,
            timestamp: $timestamp,
        );
        $normalizedEnvelope = (new IngestionEnvelopeNormalizer())->normalize($schemaEnvelope);
        $violations = (new SchemaValidator())->validate($normalizedEnvelope['envelope']);
        $diagnostics['schema'] = (new SchemaDiagnosticEmitter())->emit($violations);

        $mapped = ['nodes' => [], 'relationships' => []];
        $canMap = $policy !== 'validate_only' && $diagnostics['schema'] === [];
        if ($canMap) {
            $mapped = $this->mapRecords(
                $records,
                $defaultBundle,
                $defaultState,
                $authorId,
                $timestamp,
                $source,
                $diagnostics,
            );
        }

        $result = [
            'meta' => [
                'surface' => 'ingest_pipeline',
                'input' => $inputPath,
                'format' => $resolvedFormat,
                'source' => $source,
                'batch_id' => $normalizedEnvelope['envelope']['batch_id'] ?? $batchId,
                'policy' => $normalizedEnvelope['envelope']['policy'] ?? $policy,
                'node_count' => count($mapped['nodes']),
                'relationship_count' => count($mapped['relationships']),
                'error_count' => count($diagnostics['schema']) + count($diagnostics['errors']),
                'schema_error_count' => count($diagnostics['schema']),
                'warning_count' => count($diagnostics['warnings']),
            ],
            'nodes' => $mapped['nodes'],
            'relationships' => $mapped['relationships'],
            'diagnostics' => $diagnostics,
        ];

        $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

        $outputPath = trim((string) ($input->getOption('output') ?? ''));
        if ($outputPath !== '') {
            if (!$this->writeFile($outputPath, $encoded, $output)) {
                return Command::FAILURE;
            }
            $output->writeln(sprintf('Mapped ingest output written: %s', $outputPath));
        } else {
            $output->writeln($encoded);
        }

        $diagnosticsPath = trim((string) ($input->getOption('diagnostics-output') ?? ''));
        if ($diagnosticsPath !== '') {
            $diagnosticPayload = json_encode([
                'meta' => $result['meta'],
                'diagnostics' => $diagnostics,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            if (!$this->writeFile($diagnosticsPath, $diagnosticPayload, $output)) {
                return Command::FAILURE;
            }
            $output->writeln(sprintf('Ingest diagnostics written: %s', $diagnosticsPath));
        }

        $errorCount = count($diagnostics['schema']) + count($diagnostics['errors']);
        if ($errorCount > 0) {
            $output->writeln(sprintf('<error>Ingest completed with %d error(s).</error>', $errorCount));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Ingest completed successfully (%d nodes, %d relationships).', count($mapped['nodes']), count($mapped['relationships'])));
        return Command::SUCCESS;
    }

    /**
     * @param array{errors:list<string>,warnings:list<string>} $diagnostics
     * @return list<array<string, mixed>>
     */
    private function parseStructured(string $raw, array &$diagnostics): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $diagnostics['errors'][] = 'Structured parse failed: ' . $e->getMessage();
            return [];
        }

        if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
            $diagnostics['errors'][] = 'Structured input must be an object containing an "items" array.';
            return [];
        }

        $records = [];
        foreach ($decoded['items'] as $index => $item) {
            if (!is_array($item)) {
                $diagnostics['errors'][] = sprintf('Structured item %d is not an object.', $index);
                continue;
            }

            $records[] = [
                'source_index' => $index,
                'key' => is_string($item['key'] ?? null) ? trim($item['key']) : '',
                'title' => is_string($item['title'] ?? null) ? trim($item['title']) : '',
                'body' => is_string($item['body'] ?? null) ? trim($item['body']) : '',
                'bundle' => is_string($item['bundle'] ?? null) ? trim($item['bundle']) : '',
                'workflow_state' => is_string($item['workflow_state'] ?? null) ? strtolower(trim($item['workflow_state'])) : '',
                'source_uri' => is_string($item['source_uri'] ?? null) ? trim($item['source_uri']) : '',
                'ingested_at' => is_scalar($item['ingested_at'] ?? null) ? (string) $item['ingested_at'] : '',
                'parser_version' => is_string($item['parser_version'] ?? null) ? trim($item['parser_version']) : '',
                'relationships' => is_array($item['relationships'] ?? null) ? $item['relationships'] : [],
            ];
        }

        return $records;
    }

    /**
     * @param array{errors:list<string>,warnings:list<string>} $diagnostics
     * @return list<array<string, mixed>>
     */
    private function parseUnstructured(string $raw, array &$diagnostics): array
    {
        $records = [];
        $blocks = preg_split('/\R{2,}/', trim($raw)) ?: [];

        foreach ($blocks as $index => $block) {
            $lines = preg_split('/\R/', trim($block)) ?: [];
            if ($lines === []) {
                continue;
            }

            $title = trim((string) array_shift($lines));
            $bundle = '';
            $workflowState = '';
            $sourceUri = '';
            $ingestedAt = '';
            $parserVersion = '';
            $bodyLines = [];
            $relationships = [];

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                if (preg_match('/^Bundle:\s*(.+)$/i', $trimmed, $m) === 1) {
                    $bundle = trim($m[1]);
                    continue;
                }
                if (preg_match('/^Workflow:\s*(.+)$/i', $trimmed, $m) === 1) {
                    $workflowState = strtolower(trim($m[1]));
                    continue;
                }
                if (preg_match('/^Source:\s*(.+)$/i', $trimmed, $m) === 1) {
                    $sourceUri = trim($m[1]);
                    continue;
                }
                if (preg_match('/^IngestedAt:\s*(.+)$/i', $trimmed, $m) === 1) {
                    $ingestedAt = trim($m[1]);
                    continue;
                }
                if (preg_match('/^Parser:\s*(.+)$/i', $trimmed, $m) === 1) {
                    $parserVersion = trim($m[1]);
                    continue;
                }
                if (preg_match('/^Relates:\s*([a-z0-9_-]+)\s+([a-z0-9_:-]+)$/i', $trimmed, $m) === 1) {
                    $relationships[] = ['to' => strtolower($m[1]), 'type' => strtolower($m[2])];
                    continue;
                }
                $bodyLines[] = $trimmed;
            }

            $records[] = [
                'source_index' => $index,
                'key' => '',
                'title' => $title,
                'body' => trim(implode("\n", $bodyLines)),
                'bundle' => $bundle,
                'workflow_state' => $workflowState,
                'source_uri' => $sourceUri,
                'ingested_at' => $ingestedAt,
                'parser_version' => $parserVersion,
                'relationships' => $relationships,
            ];
        }

        if ($records === []) {
            $diagnostics['warnings'][] = 'Unstructured input contained no ingestable blocks.';
        }

        return $records;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param array{errors:list<string>,warnings:list<string>} $diagnostics
     * @return array{nodes:array<string, array<string, mixed>>,relationships:list<array<string, mixed>>}
     */
    private function mapRecords(
        array $records,
        string $defaultBundle,
        string $defaultState,
        int $authorId,
        int $timestamp,
        string $source,
        array &$diagnostics,
    ): array {
        $nodes = [];
        $pendingRelationships = [];

        foreach ($records as $index => $record) {
            $title = trim((string) ($record['title'] ?? ''));
            if ($title === '') {
                $diagnostics['errors'][] = sprintf('Record %d is missing title.', $index);
                continue;
            }

            $keySeed = trim((string) ($record['key'] ?? ''));
            $key = $this->normalizeKey($keySeed !== '' ? $keySeed : $title);
            if ($key === '') {
                $diagnostics['errors'][] = sprintf('Record %d produced an empty key.', $index);
                continue;
            }
            $key = $this->dedupeKey($key, $nodes);

            $workflowState = strtolower(trim((string) ($record['workflow_state'] ?? '')));
            if ($workflowState === '') {
                $workflowState = $defaultState;
            }
            if (!in_array($workflowState, self::VALID_STATES, true)) {
                $diagnostics['errors'][] = sprintf('Record "%s" has invalid workflow_state "%s".', $title, $workflowState);
                continue;
            }

            $bundle = trim((string) ($record['bundle'] ?? ''));
            if ($bundle === '') {
                $bundle = $defaultBundle;
            }

            $nodes[$key] = [
                'title' => $title,
                'body' => (string) ($record['body'] ?? ''),
                'type' => $bundle,
                'uid' => $authorId,
                'created' => $timestamp,
                'changed' => $timestamp,
                'status' => $workflowState === 'published' ? 1 : 0,
                'workflow_state' => $workflowState,
                'source_ref' => sprintf('%s#%s', $source, $key),
            ];

            $relationships = is_array($record['relationships'] ?? null) ? $record['relationships'] : [];
            foreach ($relationships as $relationship) {
                if (!is_array($relationship)) {
                    continue;
                }

                $to = is_string($relationship['to'] ?? null)
                    ? $this->normalizeKey((string) $relationship['to'])
                    : '';
                $type = is_string($relationship['type'] ?? null)
                    ? strtolower(trim((string) $relationship['type']))
                    : '';
                if ($to === '' || $type === '') {
                    $diagnostics['warnings'][] = sprintf('Record "%s" contains relationship with missing target/type; skipped.', $title);
                    continue;
                }

                $pendingRelationships[] = [
                    'from' => $key,
                    'to' => $to,
                    'relationship_type' => $type,
                ];
            }
        }

        ksort($nodes);

        $relationships = [];
        foreach ($pendingRelationships as $row) {
            $from = (string) $row['from'];
            $to = (string) $row['to'];
            $type = (string) $row['relationship_type'];

            if (!isset($nodes[$from])) {
                $diagnostics['errors'][] = sprintf('Relationship source key missing: %s', $from);
                continue;
            }
            if (!isset($nodes[$to])) {
                $diagnostics['errors'][] = sprintf('Relationship target key missing: %s', $to);
                continue;
            }

            $relationships[] = [
                'key' => sprintf('%s_to_%s_%s', $from, $to, $type),
                'relationship_type' => $type,
                'from' => $from,
                'to' => $to,
                'status' => 1,
                'start_date' => $timestamp,
                'end_date' => null,
                'source_ref' => sprintf('%s#%s_to_%s', $source, $from, $to),
            ];
        }

        usort($relationships, static fn(array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));

        return [
            'nodes' => $nodes,
            'relationships' => $relationships,
        ];
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array<string, mixed>
     */
    private function buildSchemaEnvelope(
        array $records,
        string $batchId,
        string $sourceSetUri,
        string $policy,
        int $timestamp,
    ): array {
        $items = [];
        foreach ($records as $record) {
            $sourceUri = trim((string) ($record['source_uri'] ?? ''));
            if ($sourceUri === '') {
                $sourceUri = trim((string) ($record['key'] ?? ''));
            }
            if ($sourceUri === '') {
                $sourceUri = $this->normalizeKey((string) ($record['title'] ?? ''));
            }

            $ingestedAt = $record['ingested_at'] ?? null;
            if ($ingestedAt === null || $ingestedAt === '') {
                $ingestedAt = $timestamp;
            }

            $items[] = [
                'source_uri' => $sourceUri,
                'ingested_at' => $ingestedAt,
                'parser_version' => ($record['parser_version'] ?? '') !== ''
                    ? (string) $record['parser_version']
                    : null,
            ];
        }

        return [
            'batch_id' => $batchId,
            'source_set_uri' => $sourceSetUri,
            'policy' => $policy,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     */
    private function dedupeKey(string $baseKey, array $nodes): string
    {
        if (!isset($nodes[$baseKey])) {
            return $baseKey;
        }

        $i = 2;
        while (isset($nodes[$baseKey . '_' . $i])) {
            $i++;
        }

        return $baseKey . '_' . $i;
    }

    private function normalizeKey(string $raw): string
    {
        $value = strtolower(trim($raw));
        $value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? '';
        $value = trim($value, '_-');

        return $value;
    }

    private function writeFile(string $path, string $contents, OutputInterface $output): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $output->writeln(sprintf('<error>Unable to create directory: %s</error>', $dir));
            return false;
        }

        if (file_put_contents($path, $contents) === false) {
            $output->writeln(sprintf('<error>Unable to write file: %s</error>', $path));
            return false;
        }

        return true;
    }
}
