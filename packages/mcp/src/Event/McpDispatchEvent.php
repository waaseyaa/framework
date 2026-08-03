<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Event;

/**
 * Fired by {@see \Waaseyaa\Mcp\McpEndpoint} once per PIPELINE STAGE, not once
 * per request (#2177 F4).
 *
 * **Cadence change.** Before F4 this fired exactly once, post-auth and
 * pre-routing, and therefore could only ever say "a request happened" — it
 * hardcoded `outcome: allowed`, named no tool, and never fired at all for a 401
 * or a 429. It now fires for each meaningful stage: an authentication rejection
 * or rate-limit refusal (with no principal / before routing), `RequestAccepted`
 * once admitted, and one terminal stage stating the real result. A listener that
 * counted events per request will see more than one; a listener that reads
 * `stage` gets the truth.
 *
 * **`params` is now always empty.** It is retained so the property shape stays
 * source-compatible for any third-party listener that reads it, but the endpoint
 * no longer populates it. A SHA-256 of the raw params could not be correlated,
 * reversed, or acted on; `safeArguments` carries the tool's own redacted
 * arguments instead, which is both safer and actually useful. Nothing in this
 * repository consumed `params` beyond hashing it.
 *
 * The name literal `waaseyaa.mcp.dispatch` is intentionally duplicated in
 * `Waaseyaa\Audit\Listener\McpDispatchAuditListener::EVENT_NAME` because mcp
 * must not require audit at runtime; equality is pinned by a cross-package
 * test (contract clause 18).
 *
 * Payload properties are read duck-typed (string-FQCN-free) by the audit
 * listener — call-graph-invisible to the dead-code gate, hence `@api`.
 *
 * @api
 */
final readonly class McpDispatchEvent
{
    public const string NAME = 'waaseyaa.mcp.dispatch';

    /**
     * @param string                  $method        JSON-RPC method name.
     * @param array<mixed>            $params        Raw JSON-RPC params. RETAINED FOR COMPATIBILITY
     *                                               ONLY and now always empty from the endpoint —
     *                                               see the class docblock. Never logged.
     * @param ?int                    $accountUid    Bearer-auth-resolved account id; null only when
     *                                               no account id is known (never coerced to 0).
     * @param string                  $correlationId Joins every record for one request.
     * @param string                  $tier          `public` or `write`.
     * @param ?string                 $stage         {@see \Waaseyaa\Foundation\Audit\AuditStage} value.
     *                                               Null only on a legacy construction.
     * @param ?string                 $toolName      The tool requested, when the method is `tools/call`.
     * @param array<array-key, mixed> $safeArguments Already redacted by the tool's own
     *                                               `argumentsForAudit()`. NEVER raw params.
     * @param array<string, mixed>    $metadata      Safe structural metadata.
     */
    public function __construct(
        public string $method,
        public array $params,
        public ?int $accountUid,
        public string $correlationId = '',
        public string $tier = 'public',
        public ?string $stage = null,
        public ?string $toolName = null,
        public array $safeArguments = [],
        public array $metadata = [],
    ) {}
}
