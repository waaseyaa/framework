<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Event;

/**
 * Fired by {@see \Waaseyaa\Mcp\McpEndpoint} exactly once per authenticated,
 * well-formed JSON-RPC request — after `authenticate()` succeeds and the
 * envelope parses with a `method` key, before method routing — covering
 * `tools/call` (FR-007) and every other JSON-RPC method per the audit
 * listener's documented contract (research D5, contract clause 16).
 *
 * `params` are RAW here — the audit listener hashes them (SHA-256) and never
 * logs the payload; the privacy property lives in the listener (contract
 * clause 17). Do not pre-hash at the dispatch site.
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
     * @param string               $method     JSON-RPC method name.
     * @param array<mixed>         $params     Raw JSON-RPC params (never logged by the listener).
     * @param ?int                 $accountUid Bearer-auth-resolved account id; null only when no
     *                                         account id is known (never coerced to 0).
     */
    public function __construct(
        public string $method,
        public array $params,
        public ?int $accountUid,
    ) {}
}
