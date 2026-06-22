<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\IngestDashboardHandler;
use Waaseyaa\CLI\Handler\IngestRunHandler;
use Waaseyaa\CLI\Handler\SearchReindexHandler;
use Waaseyaa\CLI\Handler\SemanticRefreshHandler;
use Waaseyaa\CLI\Handler\SemanticWarmHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class IngestSearchSemanticServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'ingest:run',
            description: 'Run deterministic structured/unstructured ingestion and emit mapped content payloads',
            options: [
                new HandlerOption(
                    name: 'input',
                    shortcut: 'i',
                    mode: HandlerOptionMode::Required,
                    description: 'Input file path (.json, .txt, .md)',
                ),
                new HandlerOption(
                    name: 'format',
                    shortcut: 'f',
                    mode: HandlerOptionMode::Required,
                    description: 'Input format: auto|structured|unstructured',
                    default: 'auto',
                ),
                new HandlerOption(
                    name: 'default-bundle',
                    mode: HandlerOptionMode::Required,
                    description: 'Default bundle for mapped nodes',
                    default: 'node',
                ),
                new HandlerOption(
                    name: 'default-workflow-state',
                    mode: HandlerOptionMode::Required,
                    description: 'Default workflow state',
                    default: 'draft',
                ),
                new HandlerOption(
                    name: 'author-id',
                    mode: HandlerOptionMode::Required,
                    description: 'Mapped author UID',
                    default: '1',
                ),
                new HandlerOption(
                    name: 'timestamp',
                    mode: HandlerOptionMode::Required,
                    description: 'Deterministic ingest timestamp',
                    default: '1735689600',
                ),
                new HandlerOption(
                    name: 'batch-id',
                    mode: HandlerOptionMode::Required,
                    description: 'Batch idempotency key (defaults to deterministic hash)',
                ),
                new HandlerOption(
                    name: 'policy',
                    mode: HandlerOptionMode::Required,
                    description: 'Ingestion policy: atomic_fail_fast|validate_only',
                    default: 'atomic_fail_fast',
                ),
                new HandlerOption(
                    name: 'source',
                    mode: HandlerOptionMode::Required,
                    description: 'Source identifier for audit metadata',
                    default: 'manual://default',
                ),
                new HandlerOption(
                    name: 'infer-relationships',
                    mode: HandlerOptionMode::None,
                    description: 'Infer candidate relationships from ingested text (review-safe defaults)',
                ),
                new HandlerOption(
                    name: 'authoring-assist',
                    mode: HandlerOptionMode::None,
                    description: 'Emit deterministic AI-assisted authoring suggestions',
                ),
                new HandlerOption(
                    name: 'refresh-baseline',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional baseline snapshot JSON path for refresh change detection',
                ),
                new HandlerOption(
                    name: 'refresh-snapshot-output',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional output path for current refresh snapshot JSON',
                ),
                new HandlerOption(
                    name: 'output',
                    shortcut: 'o',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional mapped output file (.json)',
                ),
                new HandlerOption(
                    name: 'diagnostics-output',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional diagnostics output file (.json)',
                ),
            ],
            handler: [IngestRunHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'ingest:dashboard',
            description: 'Build deterministic editorial dashboard summaries from ingest artifacts',
            options: [
                new HandlerOption(
                    name: 'input',
                    shortcut: 'i',
                    mode: HandlerOptionMode::Array_,
                    description: 'Ingest artifact JSON path(s) (repeat or comma-separated)',
                ),
                new HandlerOption(
                    name: 'glob',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional glob pattern for ingest artifact JSON files',
                ),
                new HandlerOption(
                    name: 'output',
                    shortcut: 'o',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional path to write dashboard payload',
                ),
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Emit machine-readable JSON payload',
                ),
            ],
            handler: [IngestDashboardHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'search:reindex',
            description: 'Rebuild the search index from all indexable entities',
            options: [
                new HandlerOption(
                    name: 'batch-size',
                    shortcut: 'b',
                    mode: HandlerOptionMode::Required,
                    description: 'Entities per batch',
                    default: '100',
                ),
            ],
            handler: [SearchReindexHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'semantic:warm',
            description: 'Warm semantic embeddings for deterministic read paths',
            options: [
                new HandlerOption(
                    name: 'type',
                    shortcut: 't',
                    mode: HandlerOptionMode::Array_,
                    description: 'Entity type ID(s) to warm (repeat option or pass comma-separated values)',
                    default: ['node'],
                ),
                new HandlerOption(
                    name: 'limit',
                    shortcut: 'l',
                    mode: HandlerOptionMode::Required,
                    description: 'Per-type candidate limit (0 = no limit)',
                    default: '0',
                ),
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Emit the full warming report as JSON',
                ),
            ],
            handler: [SemanticWarmHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'semantic:refresh',
            description: 'Run resumable semantic index refresh batches',
            options: [
                new HandlerOption(
                    name: 'type',
                    shortcut: 't',
                    mode: HandlerOptionMode::Array_,
                    description: 'Entity type ID(s) to refresh (repeat option or pass comma-separated values)',
                    default: ['node'],
                ),
                new HandlerOption(
                    name: 'batch-size',
                    shortcut: 'b',
                    mode: HandlerOptionMode::Required,
                    description: 'Maximum entities per batch execution',
                    default: '200',
                ),
                new HandlerOption(
                    name: 'cursor',
                    mode: HandlerOptionMode::Required,
                    description: 'Resume cursor JSON (e.g. {"type_index":0,"offset":200})',
                ),
                new HandlerOption(
                    name: 'until-complete',
                    mode: HandlerOptionMode::None,
                    description: 'Keep running batches until the refresh completes',
                ),
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Emit machine-readable JSON output',
                ),
            ],
            handler: [SemanticRefreshHandler::class, 'execute'],
        );
    }
}
