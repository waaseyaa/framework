<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Mcp;

use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\StorageInterface as ConfigStorageInterface;
use Waaseyaa\Foundation\Kernel\RuntimePolicy;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\ServiceProvider\Capability\CapabilityRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresCapabilitiesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\StreamHttpClient;

/**
 * Registers the remote-MCP tool source with the kernel.
 *
 * `boot()` delegates availability policy to
 * {@see McpClientToolSource::bootstrap()}: optional server failures record
 * degraded health and contribute no tools, while required failures propagate
 * as {@see McpReadinessException} and block application readiness.
 *
 * The provider stays inert on hosts that do not provide a config storage
 * or HTTP client; in that case the tool source is constructed but
 * `bootstrap()` reads an empty config and registers nothing.
 *
 * @api
 */
final class McpServiceProvider extends ServiceProvider implements RequiresCapabilitiesInterface
{
    public function register(): void
    {
        $secretRegistry = $this->resolveSecretRegistry();
        if ($secretRegistry !== null) {
            $secretRegistry->registerConsumer(McpClientToolSource::PACKAGE, McpCredentialOperation::class);
        }

        $this->singleton(McpIntegrationHealth::class, static fn(): McpIntegrationHealth => new McpIntegrationHealth());

        $this->singleton(
            StreamableHttpMcpClient::class,
            fn(): StreamableHttpMcpClient => new StreamableHttpMcpClient(
                $this->resolveHttpClient(),
                $this->resolveLogger(),
            ),
        );

        // Resolve the cross-provider tool registry only when this singleton
        // is first used. Composer may list ai-agent before ai-tools; eagerly
        // checking here would permanently disable MCP before ai-tools has
        // registered its binding.
        $this->singleton(
            McpClientToolSource::class,
            fn(): McpClientToolSource => new McpClientToolSource(
                $this->resolve(StreamableHttpMcpClient::class),
                $this->requireToolRegistry(),
                $this->resolveConfigStorage(),
                $this->resolveLogger(),
                $this->resolveSecretRegistry(),
                $this->resolve(McpIntegrationHealth::class),
            ),
        );

        $this->singleton(
            McpCapabilitiesSource::class,
            fn(): McpCapabilitiesSource => new McpCapabilitiesSource(
                $this->resolveConfigStorage(),
                $this->resolveToolRegistry() !== null ? $this->resolve(McpClientToolSource::class) : null,
            ),
        );
    }

    public function capabilityRequirements(): iterable
    {
        yield CapabilityRequirement::exact('configuration.authority.v1', 1);
    }

    public function boot(): void
    {
        if ($this->resolveToolRegistry() === null) {
            return;
        }

        $source = $this->resolve(McpClientToolSource::class);
        $source->bootstrap();
    }

    private function resolveLogger(): LoggerInterface
    {
        $candidate = $this->kernelServices?->get(LoggerInterface::class);

        return $candidate instanceof LoggerInterface ? $candidate : new NullLogger();
    }

    private function resolveHttpClient(): HttpClientInterface
    {
        $candidate = $this->kernelServices?->get(HttpClientInterface::class);
        if ($candidate instanceof HttpClientInterface) {
            return $candidate;
        }

        return new StreamHttpClient();
    }

    private function resolveToolRegistry(): ?ToolRegistryInterface
    {
        $candidate = $this->kernelServices?->get(ToolRegistryInterface::class);
        if ($candidate instanceof ToolRegistryInterface) {
            return $candidate;
        }
        // No host-bound registry — skip MCP tool registration entirely.
        // Hosts that wire ai-tools (via AttributeToolRegistry) get a
        // populated catalogue; minimal CLI smoke harnesses skip cleanly.
        return null;
    }

    private function resolveSecretRegistry(): ?SecretResolverRegistry
    {
        $candidate = $this->kernelServices?->get(SecretResolverRegistry::class);

        return $candidate instanceof SecretResolverRegistry ? $candidate : null;
    }

    private function requireToolRegistry(): ToolRegistryInterface
    {
        return $this->resolveToolRegistry()
            ?? throw new \RuntimeException('MCP tool source requires a host-bound ToolRegistryInterface.');
    }

    private function resolveConfigStorage(): ConfigStorageInterface
    {
        $candidate = $this->kernelServices?->get(ConfigStorageInterface::class);
        if ($candidate instanceof ConfigStorageInterface) {
            return $candidate;
        }
        $manager = $this->kernelServices?->get(ConfigManagerInterface::class);
        if ($manager instanceof ConfigManagerInterface) {
            return $manager->getActiveStorage();
        }

        if (RuntimePolicy::isExplicitDevelopment($this->config)) {
            return new NullConfigStorage();
        }

        throw new ConfigurationAuthorityUnavailableException(
            'MCP configuration requires configuration.authority.v1; NullConfigStorage is permitted only in explicit local/test profiles.',
        );
    }
}
