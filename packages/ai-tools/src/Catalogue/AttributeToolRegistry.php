<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Catalogue;

use Psr\Container\ContainerInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\Attribute\Capability;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Attribute-discovered tool registry.
 *
 * Walks the `agent_tools` section of {@see PackageManifest} on first
 * access and instantiates each tool class via the supplied container.
 * Wraps each in an {@see AgentTool} VO using the attribute payload
 * recorded by {@see \Waaseyaa\Foundation\Discovery\PackageManifestCompiler}.
 *
 * Hand-registered tools (e.g. dynamically-generated per-entity-type
 * tools, third-party MCP server bridges) may be added via
 * {@see register()} at any time, before or after lazy hydration.
 *
 * @api
 */
final class AttributeToolRegistry implements ToolRegistryInterface
{
    /** @var array<string, AgentTool> */
    private array $tools = [];

    private bool $hydrated = false;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly PackageManifest $manifest,
        private readonly ContainerInterface $container,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function register(AgentTool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    public function get(string $name): AgentTool
    {
        $this->hydrate();
        if (!isset($this->tools[$name])) {
            throw ToolNotFoundException::forName($name);
        }

        return $this->tools[$name];
    }

    public function has(string $name): bool
    {
        $this->hydrate();

        return isset($this->tools[$name]);
    }

    /**
     * @return iterable<AgentTool>
     */
    public function all(): iterable
    {
        $this->hydrate();

        return array_values($this->tools);
    }

    /**
     * Resolve the governed-data flag from a `#[Capability]` attribute on the class.
     *
     * Defaults to `true` (governed) when the attribute is absent — safe by default.
     *
     * @param class-string $class
     */
    private function resolveGovernedData(string $class): bool
    {
        try {
            $reflection = new \ReflectionClass($class);
            $attributes = $reflection->getAttributes(Capability::class);
            if ($attributes === []) {
                return true;
            }
            /** @var Capability $cap */
            $cap = $attributes[0]->newInstance();
            return $cap->governedData;
        } catch (\Throwable) {
            return true;
        }
    }

    private function hydrate(): void
    {
        if ($this->hydrated) {
            return;
        }
        $this->hydrated = true;

        foreach ($this->manifest->agentTools as $entry) {
            $class = $entry['class'];
            $name = $entry['name'];
            if ($name === '') {
                $this->logger->warning('AttributeToolRegistry: skipping malformed agent_tools manifest entry.');
                continue;
            }
            if (!class_exists($class)) {
                $this->logger->warning(sprintf(
                    'AttributeToolRegistry: agent_tools entry %s missing class %s; skipping.',
                    $name,
                    $class,
                ));
                continue;
            }
            try {
                $impl = $this->container->get($class);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'AttributeToolRegistry: failed to resolve %s from container: %s',
                    $class,
                    $e->getMessage(),
                ));
                continue;
            }
            if (!$impl instanceof AgentToolInterface) {
                $this->logger->error(sprintf(
                    'AttributeToolRegistry: %s must implement AgentToolInterface (got %s); skipping.',
                    $class,
                    get_debug_type($impl),
                ));
                continue;
            }

            $touchesGovernedData = $this->resolveGovernedData($class);

            $tool = new AgentTool(
                name: $name,
                capability: $entry['capability'],
                destructive: $entry['destructive'],
                dryRunSupported: $entry['dry_run_supported'],
                category: $entry['category'],
                inputSchema: $impl->inputSchema(),
                impl: $impl,
                touchesGovernedData: $touchesGovernedData,
            );
            // Hand-registered tools win over discovery (allows tests + overrides).
            if (!isset($this->tools[$tool->name])) {
                $this->tools[$tool->name] = $tool;
            }
        }
    }
}
