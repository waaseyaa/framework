<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Bridge;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;

/**
 * Per-request adapter binding the framework-wide
 * {@see AgentToolRegistryInterface} to MCP descriptors and calls.
 *
 * Construction is cheap; tool resolution is lazy through the underlying
 * `AttributeToolRegistry`. The supplied {@see AccountInterface} is forwarded
 * to every tool invocation so per-tool capability enforcement runs as the
 * initiator (see ADR-019).
 *
 * @api
 */
final class AgentToolRegistryBridge
{
    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */
    public function __construct(
        private readonly AgentToolRegistryInterface $registry,
        private readonly AccountInterface $account,
    ) {}

    public function getTools(): array
    {
        $out = [];
        foreach ($this->registry->all() as $tool) {
            $out[] = $tool;
        }

        return $out;
    }

    public function getTool(string $name): ?AgentTool
    {
        try {
            return $this->registry->get($name);
        } catch (ToolNotFoundException) {
            return null;
        }
    }

    public function execute(string $toolName, array $arguments): array
    {
        try {
            $tool = $this->registry->get($toolName);
        } catch (ToolNotFoundException $e) {
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => \json_encode(['error' => $e->getMessage()], \JSON_THROW_ON_ERROR),
                ]],
                'isError' => true,
            ];
        }

        try {
            $result = $tool->impl->execute($arguments, $this->account);
        } catch (\Throwable $e) {
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => \json_encode(['error' => $e->getMessage()], \JSON_THROW_ON_ERROR),
                ]],
                'isError' => true,
            ];
        }

        return self::toolResultToMcpEnvelope($result);
    }

    /**
     * Convert an {@see AgentToolResult} into the MCP `tools/call` envelope.
     *
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    private static function toolResultToMcpEnvelope(AgentToolResult $result): array
    {
        $envelope = ['content' => $result->content];
        if ($result->isError) {
            $envelope['isError'] = true;
        }

        return $envelope;
    }
}
