<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Bridge;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Schema\ToolInputSchemaValidator;
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
 * Every `tools/call` is validated against the tool's own advertised
 * `inputSchema` before the handler runs (#2145) — see {@see self::execute()}.
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

    /**
     * Validate the call against the tool's declared schema, then dispatch.
     *
     * The schema enforced is `AgentTool::$inputSchema` — the exact object
     * `tools/list` advertises — so what a caller is told is what the server
     * holds it to, with no second source of truth. A violation short-circuits
     * before `$tool->impl->execute()`: handlers never see malformed input, and
     * their own domain validation (Content Publishing's field-level rules, per-
     * tool capability checks) is unchanged for input that does satisfy the
     * schema.
     */
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

        $violations = ToolInputSchemaValidator::validate($tool->inputSchema, $arguments);
        if ($violations !== []) {
            return self::validationFailedEnvelope($toolName, $violations);
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
     * The established structured error envelope, reusing the machine code and
     * `{field, message}` shape Content Publishing already emits — an agent
     * parses a schema rejection exactly like a domain rejection.
     *
     * @param list<array{field: string, message: string}> $violations
     *
     * @return array{content: array<int, array{type: string, text: string}>, isError: bool}
     */
    private static function validationFailedEnvelope(string $toolName, array $violations): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => \json_encode([
                    'code' => 'VALIDATION_FAILED',
                    'message' => \sprintf(
                        'Arguments do not satisfy the declared input schema for "%s".',
                        $toolName,
                    ),
                    'errors' => $violations,
                ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            ]],
            'isError' => true,
        ];
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
