<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Mcp;

use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\Schema\Ai\McpServersConfig;
use Waaseyaa\Config\Schema\ConfigSchemaValidator;
use Waaseyaa\Config\StorageInterface as ConfigStorageInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\StreamHttpClient;

/**
 * Registers the remote-MCP tool source with the kernel.
 *
 * `boot()` calls {@see McpClientToolSource::bootstrap()} inside a try/catch
 * so a misconfigured or unreachable server cannot break the rest of the
 * kernel boot. Servers that are momentarily unavailable simply drop out
 * of the catalogue for this boot — the next boot will retry.
 *
 * The provider stays inert on hosts that do not provide a config storage
 * or HTTP client; in that case the tool source is constructed but
 * `bootstrap()` reads an empty config and registers nothing.
 *
 * @api
 */
final class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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

    public function boot(): void
    {
        // Register the schema so config sync / audit can validate the row.
        $validator = $this->kernelServices?->get(ConfigSchemaValidator::class);
        if ($validator instanceof ConfigSchemaValidator) {
            McpServersConfig::register($validator);
        }

        // Bootstrap remote tool catalogues. Failures degrade gracefully —
        // we never let a flaky MCP server abort the kernel boot.
        if ($this->resolveToolRegistry() === null) {
            return;
        }
        try {
            $source = $this->resolve(McpClientToolSource::class);
            $source->bootstrap();
        } catch (\Throwable $e) {
            $this->resolveLogger()->warning('McpClientToolSource::bootstrap() failed; continuing without remote tools', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
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

        $environment = strtolower((string) ($this->config['environment'] ?? ''));
        if (in_array($environment, ['local', 'dev', 'development', 'testing'], true)) {
            return new NullConfigStorage();
        }

        throw new ConfigurationAuthorityUnavailableException(
            'MCP configuration requires configuration.authority.v1; NullConfigStorage is permitted only in explicit local/test profiles.',
        );
    }
}
