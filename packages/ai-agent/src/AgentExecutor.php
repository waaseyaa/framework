<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent;

use Waaseyaa\AI\Agent\Provider\MaxIterationsException;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Provider\StreamingProviderInterface;

/**
 * Executes agents with safety guarantees and audit logging.
 *
 * Wraps agent execution in try/catch, logs all executions to an
 * in-memory audit log, and provides MCP tool execution on behalf
 * of agents.
 */
final class AgentExecutor
{
    /** @var AgentAuditLog[] */
    private array $auditLog = [];

    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
    ) {}

    /**
     * Execute an agent in normal mode.
     */
    public function execute(AgentInterface $agent, AgentContext $context): AgentResult
    {
        $agentId = $this->getAgentId($agent);
        $accountId = (int) $context->account->id();

        try {
            $result = $agent->execute($context);

            $this->auditLog[] = new AgentAuditLog(
                agentId: $agentId,
                accountId: $accountId,
                action: 'execute',
                success: $result->success,
                message: $result->message,
                data: $result->data,
                timestamp: \time(),
            );

            return $result;
        } catch (\Throwable $e) {
            $result = AgentResult::failure(
                message: "Agent execution failed: {$e->getMessage()}",
                data: ['exception' => $e::class],
            );

            $this->auditLog[] = new AgentAuditLog(
                agentId: $agentId,
                accountId: $accountId,
                action: 'execute',
                success: false,
                message: $result->message,
                data: $result->data,
                timestamp: \time(),
            );

            return $result;
        }
    }

    /**
     * Execute an agent in dry-run mode.
     */
    public function dryRun(AgentInterface $agent, AgentContext $context): AgentResult
    {
        $agentId = $this->getAgentId($agent);
        $accountId = (int) $context->account->id();

        try {
            $result = $agent->dryRun($context);

            $this->auditLog[] = new AgentAuditLog(
                agentId: $agentId,
                accountId: $accountId,
                action: 'dry_run',
                success: $result->success,
                message: $result->message,
                data: $result->data,
                timestamp: \time(),
            );

            return $result;
        } catch (\Throwable $e) {
            $result = AgentResult::failure(
                message: "Agent dry-run failed: {$e->getMessage()}",
                data: ['exception' => $e::class],
            );

            $this->auditLog[] = new AgentAuditLog(
                agentId: $agentId,
                accountId: $accountId,
                action: 'dry_run',
                success: false,
                message: $result->message,
                data: $result->data,
                timestamp: \time(),
            );

            return $result;
        }
    }

    /**
     * Execute an MCP tool call on behalf of an agent.
     *
     * @param string $toolName The MCP tool name to call
     * @param array<string, mixed> $arguments Tool input arguments
     * @param AgentContext $context The agent context
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    public function executeTool(string $toolName, array $arguments, AgentContext $context): array
    {
        $accountId = (int) $context->account->id();

        try {
            $result = $this->toolRegistry->execute($toolName, $arguments);
            $isError = $result['isError'] ?? false;

            $this->auditLog[] = new AgentAuditLog(
                agentId: 'tool',
                accountId: $accountId,
                action: 'tool_call',
                success: !$isError,
                message: "Tool call: {$toolName}",
                data: [
                    'tool' => $toolName,
                    'arguments' => $arguments,
                ],
                timestamp: \time(),
            );

            return $result;
        } catch (\Throwable $e) {
            $this->auditLog[] = new AgentAuditLog(
                agentId: 'tool',
                accountId: $accountId,
                action: 'tool_call',
                success: false,
                message: "Tool call failed: {$toolName} - {$e->getMessage()}",
                data: [
                    'tool' => $toolName,
                    'arguments' => $arguments,
                    'exception' => $e::class,
                ],
                timestamp: \time(),
            );

            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => \json_encode(['error' => $e->getMessage()], \JSON_THROW_ON_ERROR),
                    ],
                ],
                'isError' => true,
            ];
        }
    }

    /**
     * Execute an agent with an LLM provider, handling multi-turn tool loops.
     */
    public function executeWithProvider(
        AgentInterface $agent,
        AgentContext $context,
        ProviderInterface $provider,
    ): AgentResult {
        $agentId = $this->getAgentId($agent);
        $accountId = (int) $context->account->id();

        try {
            // Let the agent prepare the initial request
            $agentResult = $agent->execute($context);

            $messages = $context->parameters['messages'] ?? [
                ['role' => 'user', 'content' => $agentResult->message],
            ];
            $system = $context->parameters['system'] ?? null;
            $tools = $this->buildToolDefinitions();

            $iteration = 0;

            while (true) {
                $iteration++;
                if ($iteration > $context->maxIterations) {
                    throw new MaxIterationsException($context->maxIterations);
                }

                $request = new MessageRequest(
                    messages: $messages,
                    system: $system,
                    tools: $tools,
                    maxTokens: (int) ($context->parameters['max_tokens'] ?? 4096),
                );

                $response = $provider->sendMessage($request);

                // Append assistant response to conversation
                $messages[] = ['role' => 'assistant', 'content' => $response->content];

                if ($response->stopReason !== 'tool_use') {
                    break;
                }

                // Execute tool calls
                $toolResults = [];
                foreach ($response->getToolUseBlocks() as $toolUseBlock) {
                    $toolResult = $this->toolRegistry->execute($toolUseBlock->name, $toolUseBlock->input);
                    $isError = $toolResult['isError'] ?? false;
                    $resultText = $toolResult['content'][0]['text'] ?? '';

                    $this->auditLog[] = new AgentAuditLog(
                        agentId: $agentId,
                        accountId: $accountId,
                        action: 'tool_call',
                        success: !$isError,
                        message: "Tool call: {$toolUseBlock->name}",
                        data: ['tool' => $toolUseBlock->name, 'arguments' => $toolUseBlock->input],
                        timestamp: \time(),
                    );

                    $toolResults[] = (new Provider\ToolResultBlock(
                        toolUseId: $toolUseBlock->id,
                        content: $resultText,
                        isError: $isError,
                    ))->toArray();
                }

                $messages[] = ['role' => 'user', 'content' => $toolResults];
            }

            $finalText = $response->getText();

            $result = AgentResult::success(
                message: $finalText,
                data: ['usage' => $response->usage, 'iterations' => $iteration],
            );

            $this->auditLog[] = new AgentAuditLog(
                agentId: $agentId,
                accountId: $accountId,
                action: 'execute_with_provider',
                success: true,
                message: $finalText,
                data: $result->data,
                timestamp: \time(),
            );

            return $result;
        } catch (MaxIterationsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $result = AgentResult::failure(
                message: "Agent execution failed: {$e->getMessage()}",
                data: ['exception' => $e::class],
            );

            $this->auditLog[] = new AgentAuditLog(
                agentId: $agentId,
                accountId: $accountId,
                action: 'execute_with_provider',
                success: false,
                message: $result->message,
                data: $result->data,
                timestamp: \time(),
            );

            return $result;
        }
    }

    /**
     * Execute an agent with a streaming provider, forwarding chunks in real time.
     *
     * @param callable(StreamChunk): void $onChunk
     */
    public function streamWithProvider(
        AgentInterface $agent,
        AgentContext $context,
        StreamingProviderInterface $provider,
        callable $onChunk,
    ): AgentResult {
        $agentId = $this->getAgentId($agent);
        $accountId = (int) $context->account->id();

        try {
            $agentResult = $agent->execute($context);

            $messages = $context->parameters['messages'] ?? [
                ['role' => 'user', 'content' => $agentResult->message],
            ];
            $system = $context->parameters['system'] ?? null;
            $tools = $this->buildToolDefinitions();

            $iteration = 0;

            while (true) {
                $iteration++;
                if ($iteration > $context->maxIterations) {
                    throw new MaxIterationsException($context->maxIterations);
                }

                $request = new MessageRequest(
                    messages: $messages,
                    system: $system,
                    tools: $tools,
                    maxTokens: (int) ($context->parameters['max_tokens'] ?? 4096),
                );

                $response = $provider->streamMessage($request, $onChunk);

                $messages[] = ['role' => 'assistant', 'content' => $response->content];

                if ($response->stopReason !== 'tool_use') {
                    break;
                }

                // Execute tool calls synchronously between streaming rounds
                $toolResults = [];
                foreach ($response->getToolUseBlocks() as $toolUseBlock) {
                    $toolResult = $this->toolRegistry->execute($toolUseBlock->name, $toolUseBlock->input);
                    $isError = $toolResult['isError'] ?? false;
                    $resultText = $toolResult['content'][0]['text'] ?? '';

                    $this->auditLog[] = new AgentAuditLog(
                        agentId: $agentId,
                        accountId: $accountId,
                        action: 'tool_call',
                        success: !$isError,
                        message: "Tool call: {$toolUseBlock->name}",
                        data: ['tool' => $toolUseBlock->name, 'arguments' => $toolUseBlock->input],
                        timestamp: \time(),
                    );

                    $toolResults[] = (new Provider\ToolResultBlock(
                        toolUseId: $toolUseBlock->id,
                        content: $resultText,
                        isError: $isError,
                    ))->toArray();
                }

                $messages[] = ['role' => 'user', 'content' => $toolResults];
            }

            $finalText = $response->getText();

            $result = AgentResult::success(
                message: $finalText,
                data: ['usage' => $response->usage, 'iterations' => $iteration],
            );

            $this->auditLog[] = new AgentAuditLog(
                agentId: $agentId,
                accountId: $accountId,
                action: 'stream_with_provider',
                success: true,
                message: $finalText,
                data: $result->data,
                timestamp: \time(),
            );

            return $result;
        } catch (MaxIterationsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $result = AgentResult::failure(
                message: "Agent execution failed: {$e->getMessage()}",
                data: ['exception' => $e::class],
            );

            $this->auditLog[] = new AgentAuditLog(
                agentId: $agentId,
                accountId: $accountId,
                action: 'stream_with_provider',
                success: false,
                message: $result->message,
                data: $result->data,
                timestamp: \time(),
            );

            return $result;
        }
    }

    /**
     * Get the audit log.
     *
     * @return AgentAuditLog[]
     */
    public function getAuditLog(): array
    {
        return $this->auditLog;
    }

    /**
     * Build tool definitions array for the LLM provider.
     *
     * Note: Currently outputs Anthropic API format (input_schema).
     * Future providers may need format adaptation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildToolDefinitions(): array
    {
        $tools = [];
        foreach ($this->toolRegistry->getTools() as $tool) {
            $tools[] = [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->inputSchema,
            ];
        }
        return $tools;
    }

    /**
     * Derive an agent identifier string.
     */
    private function getAgentId(AgentInterface $agent): string
    {
        return $agent::class;
    }
}
