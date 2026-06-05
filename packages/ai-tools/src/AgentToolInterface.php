<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools;

use Waaseyaa\Access\AccountInterface;

/**
 * Contract implemented by every framework-shipped or third-party agent tool.
 *
 * Discovery is by class-level `#[AsAgentTool]` attribute (scanned by
 * the package manifest compiler), and registration is performed lazily by
 * {@see \Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry}.
 *
 * Implementers MUST enforce the tool's capability against the supplied
 * {@see AccountInterface} inside {@see execute()} / {@see dryRun()} — the
 * registry only enforces presence of the attribute, not authorization.
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
     * capability against {@see $account}.
     *
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, AccountInterface $account): AgentToolResult;

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
    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult;

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
