<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools;

/**
 * Contract implemented by every framework-shipped or third-party agent tool.
 *
 * Discovery is by class-level `#[AsAgentTool]` attribute (scanned by
 * the package manifest compiler), and registration is performed lazily by
 * {@see \Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry}.
 *
 * Every implementation MUST consult the {@see AgentToolContext::$entityAccessHandler}
 * per record touched during execution — unless the tool carries
 * `#[Capability(governedData: false)]`, which signals that the tool's output
 * is application metadata only and never user-data records (DIR-004 / FR-003).
 *
 * The context also carries the {@see AgentToolContext::$agentRunId} for audit
 * lineage: every AccessChecker consultation MUST be recorded in `AgentAuditLog`
 * (NFR-003 / DIR-004).
 *
 * @api
 */
interface AgentToolInterface
{
    /**
     * Execute the tool against the supplied arguments.
     *
     * Implementations MUST validate {@see $arguments} against the JSON
     * Schema returned by {@see inputSchema()} and MUST enforce the tool's
     * capability against {@see AgentToolContext::$account}.
     *
     * Tools without `#[Capability(governedData: false)]` MUST also call
     * {@see AgentToolContext::$entityAccessHandler} per entity record touched.
     *
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, AgentToolContext $context): AgentToolResult;

    /**
     * Perform a side-effect-free preview of {@see execute()}.
     *
     * When the tool declares `dryRunSupported: false` in its
     * `#[AsAgentTool]` attribute, implementations MAY delegate to
     * {@see execute()} or return an `error` result with
     * `dry_run_not_supported`. The default in
     * {@see AbstractAgentTool::dryRun()} does the latter.
     *
     * @param array<string, mixed> $arguments
     */
    public function dryRun(array $arguments, AgentToolContext $context): AgentToolResult;

    /**
     * Return a redacted view of {@see $arguments} for audit-log storage.
     *
     * The default implementation in {@see AbstractAgentTool} redacts
     * keys named `password`, `token`, and `api_key`.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function argumentsForAudit(array $arguments): array;

    /**
     * JSON Schema (draft 2020-12) describing the accepted argument shape.
     *
     * Surfaced to consumers via `tools/list` and to the agent runtime
     * for argument validation.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Human-readable one-line description surfaced via `tools/list`.
     */
    public function description(): string;
}
