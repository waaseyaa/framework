<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Bridge;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\Dispatch\AgentToolDispatcher;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * Per-request adapter binding the framework-wide
 * {@see AgentToolRegistryInterface} to MCP descriptors and calls.
 *
 * **This class is now a façade over the transport-neutral
 * {@see AgentToolDispatcher}** (ADR-022 D-9.3, #2657). Every behaviour it used
 * to implement — input-schema enforcement, output-schema enforcement,
 * exception sanitization, audit-stage classification, name-ordered listing —
 * moved into `waaseyaa/ai-tools` unchanged, and this class delegates. The
 * reason is packaging, not logic: the dispatch path never needed HTTP, but it
 * lived in `waaseyaa/mcp`, whose `McpRouteProvider` registers `/mcp/write` the
 * moment the package is installed. A local stdio plane that wanted the same
 * dispatch semantics would have had to buy an HTTP route to get them.
 *
 * The façade stays because the MCP tiers' public shape is a contract of its
 * own: `execute()` returns the bare envelope, `executeClassified()` returns
 * {@see ToolExecutionOutcome}, and the log key remains
 * `mcp.tool_execution_failed`. None of that changed.
 *
 * Construction is cheap; tool resolution is lazy through the underlying
 * `AttributeToolRegistry`. The supplied principal is forwarded to every tool
 * invocation so per-tool capability enforcement runs as the initiator (see
 * ADR-019).
 *
 * @api
 */
final class AgentToolRegistryBridge
{
    /**
     * Log-event prefix preserved from before the extraction, so
     * `mcp.tool_execution_failed` and `mcp.tool_output_schema_violation` remain
     * the keys an operator greps for.
     */
    private const string LOG_PREFIX = 'mcp';

    private readonly AgentToolDispatcher $dispatcher;

    /**
     * @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account
     * @param ?LoggerInterface $logger Destination for the detail of an unhandled
     *        tool exception. Optional so bare construction (unit tests, hosts with
     *        no logging) keeps working — it defaults to a null logger, which
     *        discards the detail. Sanitization of the RESPONSE does not depend on
     *        a logger being present: with or without one, the caller receives the
     *        same fixed envelope.
     */
    public function __construct(
        AgentToolRegistryInterface $registry,
        AuthorizationPrincipalInterface $account,
        ?LoggerInterface $logger = null,
    ) {
        $this->dispatcher = new AgentToolDispatcher($registry, $account, $logger, self::LOG_PREFIX);
    }

    /**
     * Every visible tool, ordered by name.
     *
     * @return list<AgentTool>
     */
    public function getTools(): array
    {
        return $this->dispatcher->tools();
    }

    public function getTool(string $name): ?AgentTool
    {
        return $this->dispatcher->tool($name);
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
        return $this->executeClassified($toolName, $arguments)->envelope;
    }

    /**
     * Execute and classify the result into an audit stage.
     *
     * The stage cannot be recovered from the envelope afterwards — a capability
     * refusal and an infrastructure failure both surface as `isError: true` — so
     * it is decided where the `AgentToolResult` and its `summary` are still in
     * hand. {@see execute()} is the unchanged envelope-only façade.
     */
    public function executeClassified(string $toolName, array $arguments): ToolExecutionOutcome
    {
        $outcome = $this->dispatcher->dispatch($toolName, $arguments);

        return new ToolExecutionOutcome($outcome->envelope, $outcome->stage);
    }
}
