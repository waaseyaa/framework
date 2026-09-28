<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\SemanticRefreshHandler;
use Waaseyaa\CLI\Handler\SemanticWarmHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageGate;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/** Registers semantic operator commands only for an installed and enabled ai-vector capability. */
final class SemanticServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface, RequiresOptionalPackagesInterface
{
    private const string SEMANTIC_WARM_CALLBACK = 'waaseyaa.ai-vector.semantic_warm';
    private const string SEMANTIC_REFRESH_CALLBACK = 'waaseyaa.ai-vector.semantic_refresh';

    public static function optionalPackageRequirements(): iterable
    {
        yield new OptionalPackageRequirement(
            package: 'waaseyaa/ai-vector',
            sentinelClass: 'Waaseyaa\\AI\\Vector\\EmbeddingStorageInterface',
            purpose: 'the semantic:warm and semantic:refresh operator commands',
        );
    }

    public function register(): void
    {
        if (!OptionalPackageGate::satisfied($this) || !$this->enabled()) {
            return;
        }

        $this->singleton(
            SemanticWarmHandler::class,
            fn(): SemanticWarmHandler => new SemanticWarmHandler(
                fn(array $entityTypes, int $limit): array => ($this->semanticCallback(self::SEMANTIC_WARM_CALLBACK))($entityTypes, $limit),
            ),
        );
        $this->singleton(
            SemanticRefreshHandler::class,
            fn(): SemanticRefreshHandler => new SemanticRefreshHandler(
                fn(array $entityTypes, int $batchSize, ?array $cursor): array => ($this->semanticCallback(self::SEMANTIC_REFRESH_CALLBACK))($entityTypes, $batchSize, $cursor),
            ),
        );
    }

    public function consoleCommands(): iterable
    {
        if (!OptionalPackageGate::satisfied($this) || !$this->enabled()) {
            return;
        }

        yield new HandlerCommand(
            name: 'semantic:warm',
            description: 'Warm semantic embeddings for deterministic read paths',
            options: [
                new HandlerOption(name: 'type', shortcut: 't', mode: HandlerOptionMode::Array_, description: 'Entity type ID(s) to warm (repeat option or pass comma-separated values)', default: ['node']),
                new HandlerOption(name: 'limit', shortcut: 'l', mode: HandlerOptionMode::Required, description: 'Per-type candidate limit (0 = no limit)', default: '0'),
                new HandlerOption(name: 'json', mode: HandlerOptionMode::None, description: 'Emit the full warming report as JSON'),
            ],
            handler: [SemanticWarmHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'semantic:refresh',
            description: 'Run resumable semantic index refresh batches',
            options: [
                new HandlerOption(name: 'type', shortcut: 't', mode: HandlerOptionMode::Array_, description: 'Entity type ID(s) to refresh (repeat option or pass comma-separated values)', default: ['node']),
                new HandlerOption(name: 'batch-size', shortcut: 'b', mode: HandlerOptionMode::Required, description: 'Maximum entities per batch execution', default: '200'),
                new HandlerOption(name: 'cursor', mode: HandlerOptionMode::Required, description: 'Resume cursor JSON (e.g. {"type_index":0,"offset":200})'),
                new HandlerOption(name: 'until-complete', mode: HandlerOptionMode::None, description: 'Keep running batches until the refresh completes'),
                new HandlerOption(name: 'json', mode: HandlerOptionMode::None, description: 'Emit machine-readable JSON output'),
            ],
            handler: [SemanticRefreshHandler::class, 'execute'],
        );
    }

    private function enabled(): bool
    {
        $ai = is_array($this->config['ai'] ?? null) ? $this->config['ai'] : [];

        return ($ai['vector_enabled'] ?? false) === true;
    }

    private function semanticCallback(string $serviceId): \Closure
    {
        $callback = $this->resolve($serviceId);
        if (!$callback instanceof \Closure) {
            throw new \LogicException(sprintf('The optional ai-vector callback "%s" is invalid.', $serviceId));
        }

        return $callback;
    }
}
