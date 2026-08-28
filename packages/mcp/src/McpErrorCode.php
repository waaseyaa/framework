<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

/**
 * Every JSON-RPC error code this MCP server emits, and the policy governing
 * which numbers it is allowed to choose.
 *
 * MCP 2026-07-28 partitions the JSON-RPC implementation-defined range and binds
 * a server that advertises that revision — which this one does, see
 * {@see McpProtocol::CURRENT}:
 *
 * - `-32000..-32019` is **legacy**. New codes MUST NOT be allocated there, and
 *   new implementations SHOULD NOT use the sub-range at all.
 * - `-32020..-32099` is **reserved for the MCP specification**. An
 *   implementation MUST NOT emit a code in it that the specification does not
 *   define, and MUST use a defined code only with its specified meaning. The
 *   specification defines exactly three: `-32020` HeaderMismatch, `-32021`
 *   MissingRequiredClientCapability, `-32022` UnsupportedProtocolVersion.
 * - Two codes are named as retired and MUST NOT be emitted: `-32002` (resource
 *   not found, replaced by `-32602`) and `-32042` (URL elicitation required).
 * - New codes for purposes the specification does not define SHOULD be
 *   allocated **outside** the JSON-RPC reserved range `-32768..-32000`.
 *
 * This server previously emitted nine codes the policy forbids (#2561),
 * including `-32002` with two unrelated meanings from the same server: resource
 * not found on `resources/read`, and an infrastructure outage on `tools/call`.
 * A conformant client mapping `-32002` to resource-not-found renders an audit
 * outage as a missing resource and retries a different URI instead of backing
 * off — the codes were indistinguishable, only the human-readable message
 * separated them.
 *
 * ## The allocation
 *
 * Transport, rate-limit and infrastructure refusals are purposes the MCP
 * specification does not define, so they sit outside the reserved range in the
 * `-31xxx` band. Each keeps the last two digits of the code it replaces
 * (`-32040` → `-31040`), so the migration is auditable at a glance and an
 * operator reading an old log line can find the new code without a table.
 * `-32768..-32000` is the only band JSON-RPC 2.0 reserves; `-31999..-1` is
 * explicitly available for application-defined errors.
 *
 * Codes the specification *does* define keep their spec meanings and are used
 * unchanged: `-32020`/`-32022` in {@see McpProtocolRequestValidator}, and the
 * JSON-RPC standard `-32600`/`-32601`/`-32602`/`-32700` throughout.
 *
 * The legacy `-32000..-32019` sub-range is a SHOULD NOT, not a MUST NOT, and
 * the three codes this server still emits from it are load-bearing wire
 * contracts — see {@see self::LEGACY_IN_USE}, which names each one and why it
 * stays.
 *
 * @api
 */
final class McpErrorCode
{
    // ---------------------------------------------------------------- transport

    /** The request's `Origin` is neither same-origin nor configured. */
    public const int FORBIDDEN_ORIGIN = -31040;

    /** `Accept` does not list the media types this transport profile requires. */
    public const int UNACCEPTABLE_ACCEPT = -31041;

    /** `Content-Type` is not `application/json`. */
    public const int UNSUPPORTED_CONTENT_TYPE = -31042;

    /** The request body exceeds the transport's maximum size. */
    public const int REQUEST_TOO_LARGE = -31043;

    // --------------------------------------------------------------- rate limit

    /** The caller exceeded its request budget; `data.retry_after_seconds` applies. */
    public const int RATE_LIMIT_EXCEEDED = -31029;

    /** No durable rate-limit decision could be obtained, so the request fails closed. */
    public const int RATE_LIMITER_UNAVAILABLE = -31030;

    // ----------------------------------------------------------- infrastructure

    /**
     * The audit trail could not be made durable, so the request was refused
     * before execution.
     *
     * Distinct from {@see self::APPROVAL_STORE_UNAVAILABLE}: both previously
     * shared `-32002` and were separable only by reading the message.
     */
    public const int AUDIT_TRAIL_UNAVAILABLE = -31001;

    /** The write-tier approval store could not be reached, so the request was refused. */
    public const int APPROVAL_STORE_UNAVAILABLE = -31002;

    // -------------------------------------------------------------------- policy

    /** Inclusive bounds of the range JSON-RPC 2.0 reserves for the protocol. */
    public const int JSON_RPC_RESERVED_LOW = -32768;
    public const int JSON_RPC_RESERVED_HIGH = -32000;

    /** Inclusive bounds of the sub-range MCP reserves for its own specification. */
    public const int MCP_RESERVED_LOW = -32099;
    public const int MCP_RESERVED_HIGH = -32020;

    /**
     * The only codes in {@see self::MCP_RESERVED_LOW}..{@see self::MCP_RESERVED_HIGH}
     * that MCP 2026-07-28 defines, and that this server may therefore emit.
     *
     * @var list<int>
     */
    public const array MCP_DEFINED = [-32020, -32021, -32022];

    /**
     * Codes MCP 2026-07-28 names as retired. A server advertising that revision
     * MUST NOT emit them, and a client that implemented the revision they
     * belonged to would misread them.
     *
     * @var array<int, string>
     */
    public const array RETIRED = [
        -32002 => 'resource not found (<= 2025-11-25; replaced by -32602)',
        -32042 => 'URL elicitation required (2025-11-25 only)',
    ];

    /** Inclusive bounds of the legacy sub-range new implementations SHOULD NOT use. */
    public const int LEGACY_LOW = -32019;
    public const int LEGACY_HIGH = -32000;

    /**
     * Codes this server emits from the legacy `-32000..-32019` sub-range, each
     * with the reason it is retained.
     *
     * The specification's language here is SHOULD NOT, not MUST NOT, and these
     * three are load-bearing parts of a wire contract clients already
     * implement. Renumbering them is a consumer-visible break with no MUST
     * behind it, so it is a separate decision rather than a silent rider on the
     * conformance fix (#2561). They are listed rather than tolerated by range,
     * so a NEW legacy allocation still fails the allocation test.
     *
     * @var array<int, string>
     */
    public const array LEGACY_IN_USE = [
        -32001 => 'Unauthorized. The conventional MCP authentication refusal; every client '
            . 'that talks to this server already maps it, and the 401 envelope is identical '
            . 'for every null cause by design (#1652).',
        -32003 => 'Approval required. The write-tier human-approval challenge, advertised in '
            . 'the tool descriptor so an agent can anticipate it, and answered by retrying '
            . 'with the approval request id. Renumbering breaks that handshake.',
        -32004 => 'Approval refused. The terminal half of the -32003 handshake; it moves with '
            . 'the challenge or not at all.',
    ];

    /**
     * Whether `$code` satisfies the MUST-level rules binding a server that
     * advertises MCP 2026-07-28.
     *
     * True for anything outside the JSON-RPC reserved range, for the JSON-RPC
     * standard codes, and for the three codes MCP defines. False for a retired
     * code and for an undefined code inside MCP's reserved sub-range.
     *
     * The legacy `-32000..-32019` sub-range is SHOULD-level and is reported by
     * {@see self::isLegacySubRange()} instead, so a MUST violation and a
     * discouraged-but-permitted code are never conflated.
     */
    public static function isEmittable(int $code): bool
    {
        if (isset(self::RETIRED[$code])) {
            return false;
        }
        if ($code > self::JSON_RPC_RESERVED_HIGH || $code < self::JSON_RPC_RESERVED_LOW) {
            return true;
        }

        return $code >= self::MCP_RESERVED_LOW && $code <= self::MCP_RESERVED_HIGH
            ? \in_array($code, self::MCP_DEFINED, true)
            : true;
    }

    /** Whether `$code` sits in the legacy sub-range new implementations SHOULD NOT use. */
    public static function isLegacySubRange(int $code): bool
    {
        return $code >= self::LEGACY_LOW && $code <= self::LEGACY_HIGH;
    }
}
