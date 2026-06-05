<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Mcp;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Config\Schema\Ai\McpServersConfig;
use Waaseyaa\Config\StorageInterface as ConfigStorageInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Discovers tools on every enabled remote MCP server and registers each
 * one in the local tool catalogue with a server-aliased name.
 *
 * Naming contract (per WP-07 prompt + data-model):
 *
 * - **Tool name**       `"{$alias}.{$descriptor->name}"`         (e.g. `github.create_issue`)
 * - **Capability**      `"{$capabilityPrefix}.{$descriptor->name}"` (e.g. `tool.mcp.github.create_issue`)
 * - **Category**        `"mcp.{$alias}"`
 *
 * Per-server failures are caught and logged; the rest of the catalogue
 * is built unaffected ("graceful degrade" per spec edge case).
 *
 * Each registered tool reads the auth header from
 * `getenv($auth_header_env_var)` at call time — never at config-load
 * time — per C-010.
 *
 * Tool VOs are constructed against the post-WP03 `AgentTool` surface
 * shipped by `waaseyaa/ai-tools` (one VO carries `destructive`,
 * `dryRunSupported`, `category`, `inputSchema`, and an
 * {@see \Waaseyaa\AI\Tools\AgentToolInterface} `impl`).
 *
 * @api
 */
final class McpClientToolSource
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StreamableHttpMcpClient $client,
        private readonly ToolRegistryInterface $registry,
        private readonly ConfigStorageInterface $configStorage,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Walk the configured remote MCP servers and register their tools.
     */
    public function bootstrap(): void
    {
        $raw = $this->configStorage->read(McpServersConfig::CONFIG_NAME);
        $rows = McpServersConfig::normalise($raw);

        $duplicates = McpServersConfig::hasDuplicateAliases($rows);
        if ($duplicates !== []) {
            $this->logger->warning(
                'Duplicate MCP server aliases detected; later rows shadow earlier ones',
                ['duplicate_aliases' => $duplicates],
            );
        }

        foreach ($rows as $row) {
            if (!$row['enabled']) {
                continue;
            }
            $this->bootstrapServer($row);
        }
    }

    /**
     * Return the list of capabilities derived from the current configuration,
     * suitable for surfacing via {@see McpCapabilitiesSource}.
     *
     * @return list<string>
     */
    public function discoveredCapabilities(): array
    {
        $raw = $this->configStorage->read(McpServersConfig::CONFIG_NAME);
        $rows = McpServersConfig::normalise($raw);

        $caps = [];
        foreach ($rows as $row) {
            if (!$row['enabled']) {
                continue;
            }
            try {
                $descriptors = $this->client->listTools($row['url'], $this->resolveAuthHeader($row));
            } catch (McpServerUnavailableException $e) {
                $this->logger->warning('MCP server unavailable during capability enumeration', [
                    'alias' => $row['alias'],
                    'url' => $row['url'],
                    'message' => $e->getMessage(),
                ]);
                continue;
            }
            foreach ($descriptors as $descriptor) {
                $caps[] = sprintf('%s.%s', $row['capability_prefix'], $descriptor->name);
            }
        }

        return array_values(array_unique($caps));
    }

    /**
     * @param array{
     *     alias: string,
     *     url: string,
     *     auth_header_env_var: string,
     *     enabled: bool,
     *     capability_prefix: string,
     * } $row
     */
    private function bootstrapServer(array $row): void
    {
        try {
            $authHeader = $this->resolveAuthHeader($row);
            $this->client->initialize($row['url'], $authHeader);
            $descriptors = $this->client->listTools($row['url'], $authHeader);
        } catch (McpServerUnavailableException $e) {
            $this->logger->warning('Skipping MCP server: unavailable at boot', [
                'alias' => $row['alias'],
                'url' => $row['url'],
                'message' => $e->getMessage(),
            ]);

            return;
        } catch (\Throwable $e) {
            $this->logger->warning('Skipping MCP server: unexpected error', [
                'alias' => $row['alias'],
                'url' => $row['url'],
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($descriptors as $descriptor) {
            $this->registerDescriptor($row, $descriptor);
        }
    }

    /**
     * @param array{
     *     alias: string,
     *     url: string,
     *     auth_header_env_var: string,
     *     enabled: bool,
     *     capability_prefix: string,
     * } $row
     */
    private function registerDescriptor(array $row, McpRemoteToolDescriptor $descriptor): void
    {
        $localName = sprintf('%s.%s', $row['alias'], $descriptor->name);
        $capability = sprintf('%s.%s', $row['capability_prefix'], $descriptor->name);
        $category = sprintf('mcp.%s', $row['alias']);

        // Conservative default: remote tools are destructive unless the
        // server opts out via a `destructive: false` hint in its descriptor.
        $destructive = true;
        if (array_key_exists('destructive', $descriptor->metadata)) {
            $destructive = (bool) $descriptor->metadata['destructive'];
        }

        $description = $descriptor->description !== ''
            ? $descriptor->description
            : sprintf('Remote MCP tool %s on server %s', $descriptor->name, $row['alias']);

        $impl = $this->makeRemoteToolImpl(
            client: $this->client,
            url: $row['url'],
            envVar: $row['auth_header_env_var'],
            remoteName: $descriptor->name,
            description: $description,
            inputSchema: $descriptor->inputSchema,
            capability: $capability,
            logger: $this->logger,
        );

        $tool = new AgentTool(
            name: $localName,
            capability: $capability,
            destructive: $destructive,
            dryRunSupported: false,
            category: $category,
            inputSchema: $descriptor->inputSchema,
            impl: $impl,
        );

        $this->registry->register($tool);
    }

    /**
     * Build the {@see AbstractAgentTool} implementation that proxies a
     * single remote MCP tool over Streamable HTTP.
     *
     * @param array<string, mixed> $inputSchema
     */
    private function makeRemoteToolImpl(
        StreamableHttpMcpClient $client,
        string $url,
        string $envVar,
        string $remoteName,
        string $description,
        array $inputSchema,
        string $capability,
        LoggerInterface $logger,
    ): AbstractAgentTool {
        return new class ($client, $url, $envVar, $remoteName, $description, $inputSchema, $capability, $logger) extends AbstractAgentTool {
            /**
             * @param array<string, mixed> $inputSchema
             */
            public function __construct(
                private readonly StreamableHttpMcpClient $client,
                private readonly string $url,
                private readonly string $envVar,
                private readonly string $remoteName,
                private readonly string $description,
                private readonly array $inputSchema,
                private readonly string $capability,
                private readonly LoggerInterface $logger,
            ) {}

            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                $denied = $this->requireCapability($this->capability, $account);
                if ($denied !== null) {
                    return $denied;
                }

                $authHeader = $this->resolveAuth();

                try {
                    $remote = $this->client->callTool($this->url, $authHeader, $this->remoteName, $arguments);
                } catch (McpServerUnavailableException $e) {
                    $this->logger->warning('Remote MCP tool call failed: server unavailable', [
                        'url' => $this->url,
                        'tool' => $this->remoteName,
                        'message' => $e->getMessage(),
                    ]);

                    return AgentToolResult::error(
                        message: json_encode(
                            ['error' => 'mcp_server_unavailable', 'detail' => $e->getMessage()],
                            JSON_THROW_ON_ERROR,
                        ),
                        summary: 'mcp_server_unavailable',
                    );
                }

                $blocks = $this->normaliseContent($remote->content);
                if ($remote->isError) {
                    $text = $blocks[0]['text'] ?? 'remote_error';

                    return AgentToolResult::error(message: $text, summary: 'remote_error');
                }

                return AgentToolResult::success(content: $blocks);
            }

            public function description(): string
            {
                return $this->description;
            }

            /**
             * @return array<string, mixed>
             */
            public function inputSchema(): array
            {
                return $this->inputSchema;
            }

            /**
             * @param list<array<string, mixed>> $content
             * @return list<array{type: string, text: string}>
             */
            private function normaliseContent(array $content): array
            {
                $out = [];
                foreach ($content as $block) {
                    $type = isset($block['type']) && is_string($block['type']) ? $block['type'] : 'text';
                    $text = isset($block['text']) && is_string($block['text'])
                        ? $block['text']
                        : json_encode($block, JSON_THROW_ON_ERROR);
                    $out[] = ['type' => $type, 'text' => $text];
                }

                return $out;
            }

            private function resolveAuth(): ?string
            {
                if ($this->envVar === '') {
                    return null;
                }
                $value = getenv($this->envVar);

                return ($value === false || $value === '') ? null : $value;
            }
        };
    }

    /**
     * @param array{auth_header_env_var: string} $row
     */
    private function resolveAuthHeader(array $row): ?string
    {
        if ($row['auth_header_env_var'] === '') {
            return null;
        }
        $value = getenv($row['auth_header_env_var']);

        return ($value === false || $value === '') ? null : $value;
    }
}
