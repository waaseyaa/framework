<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Mcp;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Config\Schema\Ai\McpAuthMode;
use Waaseyaa\Config\Schema\Ai\McpAvailability;
use Waaseyaa\Config\Schema\Ai\McpServersConfig;
use Waaseyaa\Config\StorageInterface as ConfigStorageInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Security\SecretConsumptionException;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolutionException;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;

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
 * Optional server failures produce explicit degraded health and no tools.
 * Required failures block readiness. Typed references resolve once across
 * startup initialize/list and once for every later tool call so rotation is
 * observed without retaining credential strings.
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
    public const string PACKAGE = 'waaseyaa/ai-agent';

    private readonly LoggerInterface $logger;

    private readonly McpIntegrationHealth $health;

    public function __construct(
        private readonly StreamableHttpMcpClient $client,
        private readonly ToolRegistryInterface $registry,
        private readonly ConfigStorageInterface $configStorage,
        ?LoggerInterface $logger = null,
        private readonly ?SecretResolverRegistry $secretResolverRegistry = null,
        ?McpIntegrationHealth $health = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->health = $health ?? new McpIntegrationHealth();
    }

    public function health(): McpIntegrationHealth
    {
        return $this->health;
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
                $descriptors = $this->withAuthentication(
                    $row,
                    fn(?string $authorization): array => $this->client->listTools($row['url'], $authorization),
                );
                $this->health->healthy($row['alias']);
            } catch (\Throwable $exception) {
                $this->handleServerFailure($row, $exception);
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
     *     auth_mode: McpAuthMode,
     *     availability: McpAvailability,
     *     credential_reference: SecretReference|null,
     *     enabled: bool,
     *     capability_prefix: string,
     * } $row
     */
    private function bootstrapServer(array $row): void
    {
        try {
            $descriptors = $this->withAuthentication(
                $row,
                function (?string $authorization) use ($row): array {
                    $this->client->initialize($row['url'], $authorization);

                    return $this->client->listTools($row['url'], $authorization);
                },
            );
            $this->health->healthy($row['alias']);
        } catch (\Throwable $exception) {
            $this->handleServerFailure($row, $exception);
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
     *     auth_mode: McpAuthMode,
     *     availability: McpAvailability,
     *     credential_reference: SecretReference|null,
     *     enabled: bool,
     *     capability_prefix: string,
     * } $row
     */
    private function registerDescriptor(array $row, McpRemoteToolDescriptor $descriptor): void
    {
        $localName = sprintf('%s.%s', $row['alias'], $descriptor->name);
        $capability = sprintf('%s.%s', $row['capability_prefix'], $descriptor->name);
        $category = sprintf('mcp.%s', $row['alias']);

        // Security (D-17): ignore any remote `destructive` hint — a remote server
        // could self-declare `destructive: false` to slip past the HITL approval
        // gate (AgentExecutor). Remote tools are ALWAYS treated as destructive.
        $destructive = true;

        $description = $descriptor->description !== ''
            ? $descriptor->description
            : sprintf('Remote MCP tool %s on server %s', $descriptor->name, $row['alias']);

        $impl = $this->makeRemoteToolImpl(
            client: $this->client,
            url: $row['url'],
            authMode: $row['auth_mode'],
            availability: $row['availability'],
            credentialReference: $row['credential_reference'],
            secretResolverRegistry: $this->secretResolverRegistry,
            remoteName: $descriptor->name,
            description: $description,
            inputSchema: $descriptor->inputSchema,
            capability: $capability,
            logger: $this->logger,
            health: $this->health,
            serverAlias: $row['alias'],
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

        // Security (D-17): never let a remote tool shadow an already-registered
        // (local or earlier-registered) tool name — a remote server must not be
        // able to hijack a trusted tool's identity.
        if ($this->registry->has($localName)) {
            $this->logger->warning('Skipping MCP tool: name already registered', ['tool' => $localName]);

            return;
        }

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
        McpAuthMode $authMode,
        McpAvailability $availability,
        ?SecretReference $credentialReference,
        ?SecretResolverRegistry $secretResolverRegistry,
        string $remoteName,
        string $description,
        array $inputSchema,
        string $capability,
        LoggerInterface $logger,
        McpIntegrationHealth $health,
        string $serverAlias,
    ): AbstractAgentTool {
        return new class ($client, $url, $authMode, $availability, $credentialReference, $secretResolverRegistry, $remoteName, $description, $inputSchema, $capability, $logger, $health, $serverAlias) extends AbstractAgentTool {
            /**
             * @param array<string, mixed> $inputSchema
             */
            public function __construct(
                private readonly StreamableHttpMcpClient $client,
                private readonly string $url,
                private readonly McpAuthMode $authMode,
                private readonly McpAvailability $availability,
                private readonly ?SecretReference $credentialReference,
                private readonly ?SecretResolverRegistry $secretResolverRegistry,
                private readonly string $remoteName,
                private readonly string $description,
                private readonly array $inputSchema,
                private readonly string $capability,
                private readonly LoggerInterface $logger,
                private readonly McpIntegrationHealth $health,
                private readonly string $serverAlias,
            ) {}

            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                $denied = $this->requireCapability($this->capability, $account);
                if ($denied !== null) {
                    return $denied;
                }

                try {
                    $remote = $this->callWithAuthentication($arguments);
                    $this->health->healthy($this->serverAlias);
                } catch (SecretResolutionException|SecretConsumptionException|McpCredentialUnavailableException) {
                    $this->recordCallFailure('credential_unavailable');

                    return AgentToolResult::error(
                        message: '{"error":"mcp_credential_unavailable"}',
                        summary: 'mcp_credential_unavailable',
                    );
                } catch (McpServerUnavailableException) {
                    $this->recordCallFailure('server_unavailable');
                    $this->logger->warning('Remote MCP tool call failed: server unavailable', [
                        'url' => $this->url,
                        'tool' => $this->remoteName,
                    ]);

                    return AgentToolResult::error(
                        message: '{"error":"mcp_server_unavailable"}',
                        summary: 'mcp_server_unavailable',
                    );
                } catch (McpRemoteErrorException) {
                    $this->logger->warning('Remote MCP tool call failed: JSON-RPC error', [
                        'url' => $this->url,
                        'tool' => $this->remoteName,
                    ]);

                    return AgentToolResult::error(
                        message: '{"error":"mcp_remote_error"}',
                        summary: 'remote_error',
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

            /** @param array<string, mixed> $arguments */
            private function callWithAuthentication(array $arguments): McpRemoteToolResult
            {
                if ($this->authMode === McpAuthMode::None) {
                    return $this->client->callTool($this->url, null, $this->remoteName, $arguments);
                }
                if ($this->credentialReference === null || $this->secretResolverRegistry === null) {
                    throw new McpCredentialUnavailableException();
                }

                $outcome = $this->secretResolverRegistry->consume(
                    $this->credentialReference,
                    McpClientToolSource::PACKAGE,
                    new McpCredentialOperation(
                        fn(#[\SensitiveParameter] string $authorization, string $version): McpCredentialOutcome => McpCredentialOutcome::capture(
                            fn(): McpRemoteToolResult => $this->client->callTool(
                                $this->url,
                                $authorization,
                                $this->remoteName,
                                $arguments,
                            ),
                            $this->url,
                        ),
                    ),
                );

                return $outcome->unwrap();
            }

            private function recordCallFailure(string $reason): void
            {
                if ($this->availability === McpAvailability::Required) {
                    $this->health->blocked($this->serverAlias, $reason);
                } else {
                    $this->health->degraded($this->serverAlias, $reason);
                }
            }
        };
    }

    /**
     * @template T
     * @param array{
     *     url: string,
     *     auth_mode: McpAuthMode,
     *     credential_reference: SecretReference|null,
     * } $row
     * @param \Closure(?string): T $operation
     * @return T
     */
    private function withAuthentication(array $row, \Closure $operation): mixed
    {
        if ($row['auth_mode'] === McpAuthMode::None) {
            return $operation(null);
        }
        if ($row['credential_reference'] === null || $this->secretResolverRegistry === null) {
            throw new McpCredentialUnavailableException();
        }

        $outcome = $this->secretResolverRegistry->consume(
            $row['credential_reference'],
            self::PACKAGE,
            new McpCredentialOperation(
                fn(#[\SensitiveParameter] string $authorization, string $version): McpCredentialOutcome => McpCredentialOutcome::capture(
                    fn(): mixed => $operation($authorization),
                    $row['url'],
                ),
            ),
        );

        return $outcome->unwrap();
    }

    /**
     * @param array{alias: string, availability: McpAvailability} $row
     */
    private function handleServerFailure(array $row, \Throwable $exception): void
    {
        $reason = $exception instanceof SecretResolutionException
            || $exception instanceof SecretConsumptionException
            || $exception instanceof McpCredentialUnavailableException
                ? 'credential_unavailable'
                : 'server_unavailable';

        if ($row['availability'] === McpAvailability::Required) {
            $this->health->blocked($row['alias'], $reason);
            throw new McpReadinessException($row['alias'], $reason);
        }

        $this->health->degraded($row['alias'], $reason);
        $this->logger->warning('Skipping optional MCP server: degraded', [
            'alias' => $row['alias'],
            'reason' => $reason,
        ]);
    }
}
