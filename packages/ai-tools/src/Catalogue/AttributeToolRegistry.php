<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Catalogue;

use Psr\Container\ContainerInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\ToolDependencyUnavailableException;
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

    /**
     * Lazy resolver for the kernel's per-entity access handler. Read once at
     * hydration (never at construction — access policies are discovered after
     * providers register). Returning null means the kernel could not supply a
     * handler; hydrated tools are still stamped {@see AbstractAgentTool::markAccessEnforced()}
     * so their per-entity guards fail closed (C-12) rather than allow-all.
     *
     * @var (\Closure(): ?EntityAccessHandler)|null
     */
    private readonly ?\Closure $accessHandlerResolver;

    /**
     * @param (\Closure(): ?EntityAccessHandler)|null $accessHandlerResolver
     *        Lazy accessor for the kernel access handler. Null (the default)
     *        leaves discovered tools capability-only — used by unit tests and
     *        hosts that construct the registry without entity-access policy.
     */
    public function __construct(
        private readonly PackageManifest $manifest,
        private readonly ContainerInterface $container,
        ?LoggerInterface $logger = null,
        ?\Closure $accessHandlerResolver = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->accessHandlerResolver = $accessHandlerResolver;
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

    private function hydrate(): void
    {
        if ($this->hydrated) {
            return;
        }
        $this->hydrated = true;

        // C-12: when the registry is constructed with an access-handler
        // resolver (production), every discovered tool that extends
        // AbstractAgentTool has the kernel handler attached and per-entity
        // enforcement turned on. If the handler resolves to null, enforcement
        // is still stamped so the tool fails closed instead of allow-all.
        $enforceAccess = $this->accessHandlerResolver !== null;
        $accessHandler = $enforceAccess ? ($this->accessHandlerResolver)() : null;

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
                // An optional tool whose dependencies are absent in this kernel
                // (routing-introspection without a RouteCollection, vector search
                // without an embedding provider) signals so by type — that is an
                // expected, recoverable skip, logged at debug so boot / tools/list
                // logs stay clean. Any OTHER throwable is a genuine failure and
                // stays at error. Either way the tool is skipped, so the resolved
                // tool set is unchanged — only the log level differs. (One
                // \Throwable catch with an instanceof branch, not two typed
                // catches: the container is typed to ContainerInterface, which is
                // not declared to throw the concrete exception.)
                if ($e instanceof ToolDependencyUnavailableException) {
                    $this->logger->debug(sprintf(
                        'AttributeToolRegistry: %s unavailable in this kernel (dependency absent); skipping. %s',
                        $class,
                        $e->getMessage(),
                    ));
                } else {
                    $this->logger->error(sprintf(
                        'AttributeToolRegistry: failed to resolve %s from container: %s',
                        $class,
                        $e->getMessage(),
                    ));
                }
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

            // C-12: stamp per-entity access onto the freshly-resolved tool.
            // markAccessEnforced() runs regardless of whether $accessHandler is
            // present so a null handler fails closed; setAccessHandler() also
            // turns enforcement on when the handler is present.
            if ($enforceAccess && $impl instanceof AbstractAgentTool) {
                $impl->markAccessEnforced();
                if ($accessHandler !== null) {
                    $impl->setAccessHandler($accessHandler);
                }
            }

            $tool = new AgentTool(
                name: $name,
                capability: $entry['capability'],
                destructive: $entry['destructive'],
                dryRunSupported: $entry['dry_run_supported'],
                category: $entry['category'],
                inputSchema: $impl->inputSchema(),
                impl: $impl,
            );
            // Hand-registered tools win over discovery (allows tests + overrides).
            if (!isset($this->tools[$tool->name])) {
                $this->tools[$tool->name] = $tool;
            }
        }
    }
}
